<?php

/**
 * OpenCart 4.0.2.3 – Admin Controller
 *
 * Az alábbi három metódust (gmailapi, getGmailAccessToken, buildGmailRawMessage)
 * másold be a meglévő admin controller osztályodba.
 *
 * Namespace példa (igazítsd a saját route-odhoz):
 *   namespace Opencart\Admin\Controller\Extension\Module;
 *
 * A 'ROUTE' placeholder-t cseréld ki a tényleges route-ra, pl.:
 *   extension/module/gmailtest
 */

// ============================================================
// Illeszd be az osztályodba az alábbi három metódust:
// ============================================================

    public function gmailapi(): void {
        $json = [];

        $this->load->language('ROUTE');

        if (!$this->user->hasPermission('modify', 'ROUTE')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            // --- Hardcoded credentials ---
            $client_id     = 'YOUR_CLIENT_ID.apps.googleusercontent.com';
            $client_secret = 'YOUR_CLIENT_SECRET';
            $refresh_token = 'YOUR_REFRESH_TOKEN';
            $from_email    = 'sender@yourdomain.com';
            $from_name     = 'Sender Name';
            $to_email      = 'recipient@example.com';
            $subject       = 'Test Email via Gmail API';
            $text          = 'This is a test email sent via Gmail API without SMTP.';

            try {
                $access_token = $this->getGmailAccessToken(
                    $client_id,
                    $client_secret,
                    $refresh_token
                );

                $raw_message = $this->buildGmailRawMessage(
                    $from_email,
                    $from_name,
                    $to_email,
                    $subject,
                    $text
                );

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

                $json['success']          = 'Email successfully sent via Gmail API.';
                $json['gmail_message_id'] = $result['id'] ?? '';
                $json['gmail_thread_id']  = $result['threadId'] ?? '';

            } catch (\Exception $e) {
                $json['error'] = $e->getMessage();
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function getGmailAccessToken(
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

    private function buildGmailRawMessage(
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

        // Base64url encoding (RFC 4648): replace +/ with -_, strip trailing =
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
