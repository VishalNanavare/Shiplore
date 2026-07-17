<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

use Throwable;

/**
 * SmsSender — delivers a short SMS (OTP, alerts) through whichever gateway is
 * configured in integration_accounts(provider='sms'). Supports Twilio, Fast2SMS,
 * MSG91 and a generic templated HTTP endpoint. When no gateway is configured (or
 * a send throws) it returns ['ok'=>false,'dev'=>true] so callers can fall back to
 * showing the code on screen in development — the flow stays fully testable
 * without a live provider, and dropping in credentials is a zero-code change.
 *
 * @see App\Controllers\Store\AccountController (mobile OTP login)
 */
final class SmsSender
{
    /**
     * @return array{ok:bool,dev:bool,provider:string,message?:string}
     */
    public function send(string $phone, string $message): array
    {
        $cfg      = service('integrationRepository')->config('sms');
        $provider = strtolower(trim((string) ($cfg['provider'] ?? '')));
        if ($provider === '') {
            return ['ok' => false, 'dev' => true, 'provider' => 'none', 'message' => 'SMS gateway not configured.'];
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        try {
            $http = \Config\Services::curlrequest(['timeout' => 12, 'http_errors' => false]);

            switch ($provider) {
                case 'twilio':
                    $sid = (string) ($cfg['account_sid'] ?? '');
                    $http->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                        'auth'        => [$sid, (string) ($cfg['auth_token'] ?? '')],
                        'form_params' => ['From' => (string) ($cfg['from'] ?? ''), 'To' => $phone, 'Body' => $message],
                    ]);
                    break;

                case 'fast2sms':
                    $http->post('https://www.fast2sms.com/dev/bulkV2', [
                        'headers'     => ['authorization' => (string) ($cfg['api_key'] ?? '')],
                        'form_params' => ['route' => 'q', 'message' => $message, 'language' => 'english', 'numbers' => $digits],
                    ]);
                    break;

                case 'msg91':
                    $http->post('https://api.msg91.com/api/v5/flow/', [
                        'headers' => ['authkey' => (string) ($cfg['authkey'] ?? ''), 'Content-Type' => 'application/json'],
                        'body'    => json_encode([
                            'template_id' => (string) ($cfg['template_id'] ?? ''),
                            'sender'      => (string) ($cfg['sender'] ?? ''),
                            'recipients'  => [['mobiles' => $digits, 'otp' => $message]],
                        ]),
                    ]);
                    break;

                default: // generic templated endpoint: {phone}/{message} placeholders
                    $url    = str_replace(['{phone}', '{message}'], [rawurlencode($digits), rawurlencode($message)], (string) ($cfg['url'] ?? ''));
                    $method = strtoupper((string) ($cfg['method'] ?? 'GET')) === 'POST' ? 'post' : 'get';
                    if ($url === '') {
                        return ['ok' => false, 'dev' => true, 'provider' => $provider, 'message' => 'Generic SMS url not set.'];
                    }
                    $http->{$method}($url);
            }

            return ['ok' => true, 'dev' => false, 'provider' => $provider];
        } catch (Throwable $e) {
            log_message('error', 'SmsSender(' . $provider . ') failed: ' . $e->getMessage());

            return ['ok' => false, 'dev' => true, 'provider' => $provider, 'message' => $e->getMessage()];
        }
    }
}
