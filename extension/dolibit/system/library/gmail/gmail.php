<?php

namespace Opencart\System\Library\Extension\Dolibit\Gmail;

/**
 * Gmail API Mailer Library – OpenCart 4.0.2.3
 *
 * Elhelyezés: extension/dolibit/system/library/gmail/gmail.php
 *
 * Regisztrálás (startup controller – már meglévő):
 *   $this->registry->set('gmail', new \Opencart\System\Library\Extension\Dolibit\Gmail\Gmail($this->registry));
 *
 * Küldés bárhol:
 *   $result = $this->gmail->send(
 *       account_id: 1,
 *       to_email:   'recipient@example.com',
 *       subject:    'Tárgy',
 *       text:       'Szöveges törzs'
 *   );
 *   if (isset($result['error'])) { ... }
 *
 * DB tábla kötelező mezői:
 *   account_id    INT PK
 *   client_id     VARCHAR
 *   client_secret VARCHAR
 *   refresh_token VARCHAR
 *   from_email    VARCHAR
 *   from_name     VARCHAR
 */
class Gmail {

    private object $db;

    /**
     * DB tábla neve prefix nélkül – igazítsd a valódi táblanévre!
     */
    private string $table = 'dolibit_google_account';

    public function __construct(\Opencart\System\Engine\Registry $registry) {
        $this->db = $registry->get('db');
    }

    // ----------------------------------------------------------------
    // Publikus API
    // ----------------------------------------------------------------

    /**
     * E-mail küldése Gmail API-n keresztül.
     *
     * @param int    $account_id  Google fiók azonosítója az adatbázisban
     * @param string $to_email    Címzett e-mail cím
     * @param string $subject     Tárgy
     * @param string $text        Törzs (plain text)
     * @param string $from_name   Opcionális: felülírja a DB-ben tárolt nevet
     *
     * @return array{success?: string, gmail_message_id?: string, gmail_thread_id?: string, error?: string}
     */
    public function send(
        int    $account_id,
        string $to_email,
        string $subject,
        string $text,
        string $from_name = ''
    ): array {
        try {
            $account = $this->getAccount($account_id);

            $access_token = $this->fetchAccessToken(
                $account['client_id'],
                $account['client_secret'],
                $account['refresh_token']
            );

            $raw = $this->buildRawMessage(
                $account['from_email'],
                $from_name ?: $account['from_name'],
                $to_email,
                $subject,
                $text
            );

            return $this->dispatchToGmailApi($access_token, $raw);

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ----------------------------------------------------------------
    // Privát segédmetódusok
    // ----------------------------------------------------------------

    private function getAccount(int $account_id): array {
        $query = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . $this->table . "`
             WHERE `account_id` = '" . (int)$account_id . "'
             LIMIT 1"
        );

        if (empty($query->row)) {
            throw new \Exception(
                'Gmail account not found: account_id=' . $account_id
            );
        }

        foreach (['client_id', 'client_secret', 'refresh_token', 'from_email', 'from_name'] as $field) {
            if (empty($query->row[$field])) {
                throw new \Exception(
                    'Gmail account missing field "' . $field . '" for account_id=' . $account_id
                );
            }
        }

        return $query->row;
    }

    private function fetchAccessToken(
        string $client_id,
        string $client_secret,
        string $refresh_token
    ): string {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]));

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('cURL error (token): ' . curl_error($ch));
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new \Exception(
                'OAuth2 token refresh failed (HTTP ' . $http_code . '): ' . $response
            );
        }

        $data = json_decode($response, true);

        if (empty($data['access_token'])) {
            throw new \Exception(
                'OAuth2 response missing access_token: ' . $response
            );
        }

        return (string) $data['access_token'];
    }

    private function buildRawMessage(
        string $from_email,
        string $from_name,
        string $to_email,
        string $subject,
        string $text
    ): string {
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encoded_body    = chunk_split(base64_encode($text), 76, "\r\n");

        $raw  = 'From: ' . $from_name . ' <' . $from_email . '>' . "\r\n";
        $raw .= 'To: ' . $to_email . "\r\n";
        $raw .= 'Subject: ' . $encoded_subject . "\r\n";
        $raw .= 'MIME-Version: 1.0' . "\r\n";
        $raw .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $raw .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $raw .= "\r\n";
        $raw .= $encoded_body;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function dispatchToGmailApi(string $access_token, string $raw_message): array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['raw' => $raw_message]));

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('cURL error (send): ' . curl_error($ch));
        }

        curl_close($ch);

        $result = json_decode($response, true);

        if ($http_code !== 200) {
            $error_message = $result['error']['message'] ?? 'Unknown Gmail API error';
            throw new \Exception('Gmail API error (HTTP ' . $http_code . '): ' . $error_message);
        }

        return [
            'success'          => 'Email sent successfully via Gmail API.',
            'gmail_message_id' => $result['id'] ?? '',
            'gmail_thread_id'  => $result['threadId'] ?? '',
        ];
    }
}
