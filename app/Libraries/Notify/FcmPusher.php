<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

use Throwable;

/**
 * FcmPusher — sends FCM push notifications via the HTTP v1 API.
 * Uses the service-account JSON stored in integration_accounts to obtain an
 * OAuth 2.0 bearer token (JWT grant). Falls back to the legacy server key if no
 * service_account_json is configured.
 */
final class FcmPusher
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FCM_BASE  = 'https://fcm.googleapis.com/v1/projects/';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param list<string>        $tokens
     * @param array<string,mixed> $data   Extra key/value pairs (data payload sent alongside the notification).
     * @return array{sent:int,failed:int,errors:list<string>}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'errors' => []];
        }

        $config = service('integrationRepository')->config('firebase');

        if (! empty($config['service_account_json'])) {
            return $this->sendV1($tokens, $title, $body, $data, (string) $config['service_account_json']);
        }

        if (! empty($config['server_key'])) {
            return $this->sendLegacy($tokens, $title, $body, $data, (string) $config['server_key']);
        }

        return ['sent' => 0, 'failed' => count($tokens), 'errors' => ['no_firebase_credentials']];
    }

    // ── FCM HTTP v1 (service account OAuth 2.0) ──────────────────────────────

    /** @param array<string,mixed> $data */
    private function sendV1(array $tokens, string $title, string $body, array $data, string $saJson): array
    {
        try {
            $sa = json_decode($saJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['sent' => 0, 'failed' => count($tokens), 'errors' => ['invalid_service_account_json']];
        }

        $projectId   = (string) ($sa['project_id'] ?? '');
        $accessToken = $this->getAccessToken($sa);

        if ($accessToken === null || $projectId === '') {
            return ['sent' => 0, 'failed' => count($tokens), 'errors' => ['oauth_token_failed']];
        }

        $url     = self::FCM_BASE . $projectId . '/messages:send';
        $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];

        $sent = 0; $failed = 0; $errors = [];

        // FCM v1 is one-token-per-request (unlike the legacy multicast endpoint).
        foreach ($tokens as $token) {
            $payload  = (string) json_encode($this->buildV1Message($token, $title, $body, $data));
            $response = $this->httpPost($url, $payload, $headers);

            if (isset($response['name'])) {
                $sent++;
            } else {
                $failed++;
                $errCode  = (string) ($response['error']['status'] ?? 'UNKNOWN');
                $errors[] = substr($token, -8) . ':' . $errCode;
                // Invalid / unregistered tokens should be retired so we don't keep retrying them.
                if (in_array($errCode, ['INVALID_ARGUMENT', 'NOT_FOUND', 'UNREGISTERED'], true)) {
                    service('deviceTokenRepository')->markInvalid($token);
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    /** Mint a short-lived OAuth 2.0 bearer token via a signed JWT assertion. */
    private function getAccessToken(mixed $sa): ?string
    {
        try {
            $now    = time();
            $header  = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims  = $this->b64url((string) json_encode([
                'iss'   => $sa['client_email'],
                'scope' => self::FCM_SCOPE,
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));
            $signingInput = $header . '.' . $claims;
            $signature    = '';
            openssl_sign($signingInput, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = $signingInput . '.' . $this->b64url($signature);

            $response = $this->httpPost(self::TOKEN_URL, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]), ['Content-Type: application/x-www-form-urlencoded']);

            return (string) ($response['access_token'] ?? '') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    // ── FCM legacy (server key) ───────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function sendLegacy(array $tokens, string $title, string $body, array $data, string $serverKey): array
    {
        $payload = (string) json_encode([
            'registration_ids' => $tokens,
            'notification'     => ['title' => $title, 'body' => $body, 'sound' => 'default'],
            'data'             => array_map('strval', $data),
            'android'          => ['priority' => 'high'],
            'priority'         => 'high',
        ]);
        $response = $this->httpPost('https://fcm.googleapis.com/fcm/send', $payload, [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ]);

        $success = (int) ($response['success'] ?? 0);
        $failure = (int) ($response['failure'] ?? count($tokens));

        return ['sent' => $success, 'failed' => $failure, 'errors' => []];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a single FCM v1 message envelope.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildV1Message(string $token, string $title, string $body, array $data): array
    {
        return [
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => [
                    'priority'     => 'high',
                    'notification' => ['channel_id' => 'shiplore_main', 'sound' => 'default'],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => [
                        'aps' => [
                            'badge' => isset($data['badge']) ? (int) $data['badge'] : 1,
                            'sound' => 'default',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param list<string>        $headers
     * @return array<string,mixed>
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);

        return (array) (json_decode($raw, true) ?: []);
    }
}
