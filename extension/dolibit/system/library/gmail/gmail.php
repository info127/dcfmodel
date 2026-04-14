<?php

namespace Opencart\System\Library\Extension\Dolibit\Gmail;

class Gmail {
    protected $registry;

    public function __construct($registry) {
        $this->registry = $registry;
    }

    // =========================================================================
    //  PUBLIKUS API
    // =========================================================================

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

            $access_token = $this->getAccessToken($account);

            $raw = $this->buildRawMessage(
                $account['from_email'],
                $from_name ?: ($account['from_name'] ?? $account['name'] ?? ''),
                $to_email,
                $subject,
                $text
            );

            return $this->dispatchToGmailApi($access_token, $raw);

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Teszt e-mail küldése a fiók saját címére.
     *
     * Használat: $this->gmail->testEmail(1);
     *
     * @param int $account_id  Google fiók azonosítója az adatbázisban
     * @return array{success?: string, gmail_message_id?: string, gmail_thread_id?: string, error?: string}
     */
    public function testEmail(int $account_id): array {
        try {
            $account = $this->getAccount($account_id);

            return $this->send(
                account_id: $account_id,
                to_email:   $account['from_email'],
                subject:    '[TEST] Gmail API – account_id=' . $account_id,
                text:       "Ez egy automatikus teszt e-mail.\n\n"
                          . "Account ID : " . $account_id . "\n"
                          . "From       : " . $account['from_email'] . "\n"
                          . "Auth type  : " . ($account['auth_type'] ?? 'oauth') . "\n"
                          . "Időbélyeg  : " . date('Y-m-d H:i:s') . "\n"
            );

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    //  GOOGLE AUTH (belső – gdrive mintájára)
    // =========================================================================

    private function getAccessToken(array $account): string {
        if (!$account) {
            throw new \Exception('Üres account tömb.');
        }

        if ($account['auth_type'] === 'service_account') {
            // Gmail API service account csak Google Workspace domain delegation esetén működik.
            $authFile = DIR_STORAGE . 'google_oauth/' . $account['filename'];

            if (!file_exists($authFile)) {
                throw new \Exception('Service Account JSON nem található: ' . $authFile);
            }

            $client = new \Google\Client();
            $client->setAuthConfig($authFile);
            $client->setScopes([$account['scopes'] ?: 'https://www.googleapis.com/auth/gmail.send']);
            // Domain-wide delegation esetén szükséges:
            // $client->setSubject($account['impersonate_email']);

            $token = $client->fetchAccessTokenWithAssertion();

            if (empty($token['access_token'])) {
                throw new \Exception('Service Account token lekérés sikertelen.');
            }

            return (string) $token['access_token'];
        }

        // --- OAuth2 flow ---

        if (empty($account['access_token'])) {
            throw new \Exception('OAuth access_token hiányzik az account_id: ' . ($account['account_id'] ?? '?'));
        }

        // Ha a tárolt access_token még érvényes (60 mp biztonsági sávval), azt használjuk
        if (!empty($account['expires_at']) && strtotime($account['expires_at']) > time() + 60) {
            return (string) $account['access_token'];
        }

        // Lejárt → refresh_token alapján frissítés (cURL)
        if (empty($account['refresh_token'])) {
            throw new \Exception('refresh_token hiányzik az account_id: ' . ($account['account_id'] ?? '?'));
        }

        return $this->refreshAccessToken(
            $account['client_id'],
            $account['client_secret'],
            $account['refresh_token']
        );
    }

    private function refreshAccessToken(
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

    // =========================================================================
    //  GMAIL SEND (belső)
    // =========================================================================

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

        // Base64url (RFC 4648): +→- /→_ trailing= eltávolítva
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

    // =========================================================================
    //  DB (belső)
    // =========================================================================

    private function getAccount(int $account_id): array {
        $db = $this->registry->get('db');

        $query = $db->query(
            "SELECT * FROM `" . DB_PREFIX . "dolibit_google_account`
             WHERE `account_id` = '" . (int)$account_id . "'
             LIMIT 1"
        );

        if (empty($query->row)) {
            throw new \Exception('Gmail account not found: account_id=' . $account_id);
        }

        return $query->row;
    }
}
