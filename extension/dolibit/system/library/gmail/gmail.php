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
     * @param int    $account_id  Google fiók azonosítója (doliBIT_gdrive_account.account_id)
     * @param string $to_email    Címzett e-mail cím
     * @param string $subject     Tárgy
     * @param string $text        Törzs (plain text)
     * @param string $from_email  Küldő e-mail cím (nincs DB-ben, kötelező OAuth-hoz)
     * @param string $from_name   Küldő neve (opcionális, fallback: account.name)
     *
     * @return array{success?: string, gmail_message_id?: string, gmail_thread_id?: string, error?: string}
     */
    /**
     * E-mail küldése Gmail API-n keresztül.
     *
     * Ha $html meg van adva → multipart/alternative (HTML elsődleges, plain fallback).
     * Ha $html üres        → egyszerű text/plain.
     *
     * @param int    $account_id  Google fiók azonosítója
     * @param string $to_email    Címzett e-mail cím
     * @param string $subject     Tárgy
     * @param string $text        Törzs plain text (kötelező, HTML esetén fallback)
     * @param string $html        HTML változat (opcionális)
     * @param string $from_email  Küldő e-mail cím (nincs DB-ben)
     * @param string $from_name   Küldő neve (opcionális, fallback: account.name)
     *
     * @return array{success?: string, gmail_message_id?: string, gmail_thread_id?: string, error?: string}
     */
    public function send(
        int    $account_id,
        string $to_email,
        string $subject,
        string $text,
        string $html       = '',
        string $from_email = '',
        string $from_name  = ''
    ): array {
        try {
            $account = $this->getAccount($account_id);

            $access_token = $this->getAccessToken($account);

            $sender_name = $from_name ?: ($account['name'] ?? '');

            if ($html !== '') {
                $raw = $this->buildAlternativeRawMessage(
                    $from_email,
                    $sender_name,
                    $to_email,
                    $subject,
                    $text,
                    $html
                );
            } else {
                $raw = $this->buildRawMessage(
                    $from_email,
                    $sender_name,
                    $to_email,
                    $subject,
                    $text
                );
            }

            return $this->dispatchToGmailApi($access_token, $raw);

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Komplex MIME e-mail küldése előre megépített headers + body alapján.
     *
     * Használd ezt, ha a hívó már összerakta a multipart/mixed vagy
     * multipart/alternative struktúrát (pl. HTML + plain + PDF melléklet).
     *
     * A $mime_headers tartalmazhat: MIME-Version, From, Content-Type (boundary).
     * A To és Subject innen kerül be, NE legyen benne a $mime_headers-ben.
     *
     * Használat:
     *   $result = $this->gmail->sendMime(
     *       account_id:   8,
     *       to_email:     $to_email,
     *       subject:      $subject,
     *       mime_headers: $headers,
     *       mime_body:    $email_body
     *   );
     *
     * @param int    $account_id   Google fiók azonosítója
     * @param string $to_email     Címzett e-mail cím
     * @param string $subject      Tárgy
     * @param string $mime_headers Fejléc blokk (From, MIME-Version, Content-Type stb.)
     * @param string $mime_body    MIME törzs (boundary-s részekkel együtt)
     *
     * @return array{success?: string, gmail_message_id?: string, gmail_thread_id?: string, error?: string}
     */
    public function sendMime(
        int    $account_id,
        string $to_email,
        string $subject,
        string $mime_headers,
        string $mime_body
    ): array {
        try {
            $account = $this->getAccount($account_id);

            $access_token = $this->getAccessToken($account);

            $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            // Teljes RFC 2822 üzenet összerakása:
            // To + Subject előre, utána a hívó által épített fejlécek, majd üres sor, majd törzs
            $raw_message  = 'To: '      . $to_email        . "\r\n";
            $raw_message .= 'Subject: ' . $encoded_subject . "\r\n";
            $raw_message .= rtrim($mime_headers, "\r\n")   . "\r\n";
            $raw_message .= "\r\n";
            $raw_message .= $mime_body;

            $encoded = rtrim(strtr(base64_encode($raw_message), '+/', '-_'), '=');

            return $this->dispatchToGmailApi($access_token, $encoded);

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Teszt e-mail küldése.
     *
     * Használat: $this->gmail->testEmail(1, 'info@webdock.hu');
     *
     * @param int    $account_id  Google fiók azonosítója
     * @param string $to_email    Hova menjen a teszt levél
     * @param string $from_email  Küldő e-mail cím
     * @return array
     */
    public function testEmail(int $account_id, string $to_email, string $from_email): array {
        try {
            $account = $this->getAccount($account_id);

            return $this->send(
                account_id: $account_id,
                to_email:   $to_email,
                subject:    '[TEST] Gmail API – account_id=' . $account_id,
                text:       "Ez egy automatikus teszt e-mail.\n\n"
                          . "Account ID : " . $account_id . "\n"
                          . "Account    : " . ($account['name'] ?? '') . "\n"
                          . "Auth type  : " . ($account['auth_type'] ?? 'oauth') . "\n"
                          . "From       : " . $from_email . "\n"
                          . "To         : " . $to_email . "\n"
                          . "Időbélyeg  : " . date('Y-m-d H:i:s') . "\n",
                from_email: $from_email
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
            if (empty($account['service_json'])) {
                throw new \Exception('Service Account JSON hiányzik az account_id: ' . ($account['account_id'] ?? '?'));
            }

            $authConfig = json_decode($account['service_json'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Service Account JSON érvénytelen az account_id: ' . ($account['account_id'] ?? '?'));
            }

            $client = new \Google\Client();
            $client->setAuthConfig($authConfig);
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

    /**
     * multipart/alternative MIME üzenet: plain text (fallback) + HTML (elsődleges).
     * Az email kliensek az utolsó részt részesítik előnyben → HTML kerül utoljára.
     */
    private function buildAlternativeRawMessage(
        string $from_email,
        string $from_name,
        string $to_email,
        string $subject,
        string $text,
        string $html
    ): string {
        $boundary        = md5(uniqid('alt_', true));
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $raw = '';

        if ($from_email !== '') {
            $raw .= 'From: ' . $from_name . ' <' . $from_email . '>' . "\r\n";
        }

        $raw .= 'To: '      . $to_email        . "\r\n";
        $raw .= 'Subject: ' . $encoded_subject  . "\r\n";
        $raw .= 'MIME-Version: 1.0'             . "\r\n";
        $raw .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
        $raw .= "\r\n";

        // --- plain text rész (fallback) ---
        $raw .= '--' . $boundary . "\r\n";
        $raw .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $raw .= 'Content-Transfer-Encoding: base64'       . "\r\n";
        $raw .= "\r\n";
        $raw .= chunk_split(base64_encode($text), 76, "\r\n");
        $raw .= "\r\n";

        // --- HTML rész (elsődleges – utolsó helyen) ---
        $raw .= '--' . $boundary . "\r\n";
        $raw .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $raw .= 'Content-Transfer-Encoding: base64'      . "\r\n";
        $raw .= "\r\n";
        $raw .= chunk_split(base64_encode($html), 76, "\r\n");
        $raw .= "\r\n";

        $raw .= '--' . $boundary . '--';

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
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

        $raw = '';

        if ($from_email !== '') {
            $raw .= 'From: ' . $from_name . ' <' . $from_email . '>' . "\r\n";
        }

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

    /**
     * Account + token JOIN lekérdezés.
     * Táblák: doliBIT_gdrive_account + doliBIT_gdrive_token
     */
    private function getAccount(int $account_id): array {
        $db = $this->registry->get('db');

        $query = $db->query(
            "SELECT a.*, t.access_token, t.refresh_token, t.expires_at
             FROM `"   . DB_PREFIX . "doliBIT_gdrive_account` a
             LEFT JOIN `" . DB_PREFIX . "doliBIT_gdrive_token` t
                ON t.account_id = a.account_id
             WHERE a.account_id = '" . (int)$account_id . "'
             ORDER BY t.date_modified DESC
             LIMIT 1"
        );

        if (empty($query->row)) {
            throw new \Exception('Gmail account not found: account_id=' . $account_id);
        }

        $this->registry->get('log')->write(print_r($query->row, 1));

        return $query->row;
    }
}
