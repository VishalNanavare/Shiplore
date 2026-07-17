<?php

declare(strict_types=1);

namespace App\Libraries\Notify;

use Throwable;

/**
 * Mailer — sends transactional email over the SMTP account configured in the
 * admin Integrations panel (integration_accounts, provider "smtp"). It builds a
 * fully-initialised CodeIgniter Email instance per send from that saved config,
 * so the Gmail/SMTP credentials entered in the UI are actually used.
 *
 * Config\Email is intentionally left at framework defaults (protocol "mail");
 * the source of truth is the saved integration, injected here by the service
 * factory (Config\Services::mailer()).
 *
 * Never throws — returns false when SMTP is not configured or the send fails, so
 * callers (password reset, the notification worker, the "Test" button) stay
 * fail-safe.
 *
 * @see App\Controllers\Admin\IntegrationController  where the config is saved
 */
final class Mailer
{
    /**
     * @param array<string,mixed> $cfg saved SMTP config:
     *        host, port, username, password, encryption(tls|ssl|none), from_email, from_name
     */
    public function __construct(private array $cfg) {}

    /** True when enough SMTP config is present to attempt a send. */
    public function configured(): bool
    {
        return $this->str('host') !== '' && $this->str('username') !== '';
    }

    /** Send one HTML email. Returns true on success; never throws. */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if ($to === '' || ! $this->configured()) {
            return false;
        }

        $crypto = $this->str('encryption') ?: 'tls';
        $from   = $this->str('from_email') ?: $this->str('username');
        $name   = $this->str('from_name') ?: 'Shiplore';

        try {
            $email = service('email', null, false);
            $email->initialize([
                'protocol'    => 'smtp',
                'SMTPHost'    => $this->str('host'),
                'SMTPPort'    => (int) ($this->cfg['port'] ?? 587),
                'SMTPUser'    => $this->str('username'),
                'SMTPPass'    => $this->str('password'),
                'SMTPCrypto'  => $crypto === 'none' ? '' : $crypto,
                'SMTPTimeout' => 15,
                'mailType'    => 'html',
                'charset'     => 'UTF-8',
                'fromEmail'   => $from,
                'fromName'    => $name,
            ]);
            $email->setFrom($from, $name);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($htmlBody);

            if ($email->send(false)) {
                return true;
            }

            log_message('error', "Mailer: SMTP send to {$to} failed. " . $email->printDebugger(['headers']));
        } catch (Throwable $e) {
            log_message('error', 'Mailer: ' . $e->getMessage());
        }

        return false;
    }

    private function str(string $key): string
    {
        return trim((string) ($this->cfg[$key] ?? ''));
    }
}
