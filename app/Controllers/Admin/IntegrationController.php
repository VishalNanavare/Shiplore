<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\IntegrationController — config panels for Firebase (OTP/push), Email
 * (SMTP) and the GST verification API. One declarative spec per provider drives
 * the form, save (to integration_accounts) and a validation "test". Guarded by
 * `integration.manage`; POSTs CSRF-protected.
 *
 * @see docs/architecture/11-NOTIFICATION-ARCHITECTURE.md
 * @see docs/architecture/10-GST-ARCHITECTURE.md
 */
final class IntegrationController extends BaseController
{
    /**
     * URL slug => provider spec. `provider` is the integration_accounts key.
     * Each field: [key, label, type(text|password|textarea|select), required, options?].
     */
    private const SPECS = [
        'firebase' => [
            'provider' => 'firebase', 'label' => 'Firebase', 'icon' => 'bi-fire',
            'blurb'    => 'Used for mobile OTP and push notifications.',
            'fields'   => [
                ['project_id', 'Project ID', 'text', true],
                ['web_api_key', 'Web API Key', 'password', true],
                ['sender_id', 'Messaging Sender ID', 'text', true],
                ['app_id', 'App ID', 'text', false],
                ['server_key', 'Server Key (legacy)', 'password', false],
                ['service_account_json', 'Service Account JSON', 'textarea', false],
            ],
        ],
        'email' => [
            'provider' => 'smtp', 'label' => 'Email (SMTP)', 'icon' => 'bi-envelope',
            'blurb'    => 'Transactional email — order updates, password resets, invoices.',
            'fields'   => [
                ['host', 'SMTP Host', 'text', true],
                ['port', 'Port', 'text', true],
                ['username', 'Username', 'text', true],
                ['password', 'Password', 'password', true],
                ['encryption', 'Encryption', 'select', false, ['tls', 'ssl', 'none']],
                ['from_email', 'From Email', 'text', true],
                ['from_name', 'From Name', 'text', false],
            ],
        ],
        'gst-api' => [
            'provider' => 'gst_api', 'label' => 'GST Verification API', 'icon' => 'bi-receipt',
            'blurb'    => 'Validates a vendor GSTIN against the GST portal before onboarding completes.',
            'fields'   => [
                ['provider_name', 'API Provider', 'text', true],
                ['base_url', 'Base URL', 'text', true],
                ['client_id', 'Client ID', 'text', true],
                ['client_secret', 'Client Secret', 'password', true],
                ['api_key', 'API Key', 'password', false],
            ],
        ],
        'google-maps' => [
            'provider' => 'google_maps', 'label' => 'Google Maps', 'icon' => 'bi-geo-alt',
            'blurb'    => 'Address search + map marker on vendor registration (Maps JavaScript + Places + Geocoding).',
            'fields'   => [
                ['browser_key', 'Maps JavaScript API Key', 'text', true],
            ],
        ],
    ];

    public function show(string $slug)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $spec = $this->spec($slug);

        return view('admin/integrations/form', [
            'title'     => $spec['label'] . ' · Admin',
            'pageTitle' => $spec['label'],
            'active'    => 'int-' . $slug,
            'userName'  => session()->get('user_name') ?: 'User',
            'slug'      => $slug,
            'spec'      => $spec,
            'row'       => service('integrationRepository')->get($spec['provider']),
        ]);
    }

    public function save(string $slug): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $spec = $this->spec($slug);

        $config = [];
        foreach ($spec['fields'] as $f) {
            $config[$f[0]] = trim((string) $this->request->getPost($f[0]));
        }

        service('integrationRepository')->upsert($spec['provider'], $config, 'connected', (int) session()->get('user_id'));

        return redirect()->to('admin/integrations/' . $slug)->with('success', $spec['label'] . ' settings saved.');
    }

    public function test(string $slug): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $spec   = $this->spec($slug);
        $config = service('integrationRepository')->config($spec['provider']);

        $missing = [];
        foreach ($spec['fields'] as $f) {
            if (($f[3] ?? false) && trim((string) ($config[$f[0]] ?? '')) === '') {
                $missing[] = $f[1];
            }
        }

        if ($missing !== []) {
            service('integrationRepository')->setStatus($spec['provider'], 'error', (int) session()->get('user_id'));

            return redirect()->to('admin/integrations/' . $slug)->with('error', 'Missing: ' . implode(', ', $missing));
        }

        // The SMTP provider is proven by actually sending a test email to the
        // configured From address — not just by checking the fields are present.
        if ($spec['provider'] === 'smtp') {
            $to = trim((string) ($config['from_email'] ?? '')) ?: trim((string) ($config['username'] ?? ''));
            $ok = service('mailer')->send(
                $to,
                service('settingsRepository')->brandName() . ' SMTP test',
                '<p>Your SMTP settings work — this is a test email from ' . service('settingsRepository')->brandName() . '.</p>'
            );
            service('integrationRepository')->setStatus($spec['provider'], $ok ? 'connected' : 'error', (int) session()->get('user_id'));

            return redirect()->to('admin/integrations/' . $slug)->with(
                $ok ? 'success' : 'error',
                $ok
                    ? 'Test email sent to ' . $to . ' — check that inbox to confirm delivery.'
                    : 'Could not send a test email. Check host/port/username/password (Gmail needs an App Password, not your login password) — see writable/logs for the exact SMTP error.'
            );
        }

        service('integrationRepository')->setStatus($spec['provider'], 'connected', (int) session()->get('user_id'));

        return redirect()->to('admin/integrations/' . $slug)->with('success', $spec['label'] . ' configuration is valid.');
    }

    /** @return array<string,mixed> */
    private function spec(string $slug): array
    {
        if (! isset(self::SPECS[$slug])) {
            throw PageNotFoundException::forPageNotFound('Unknown integration: ' . $slug);
        }

        return self::SPECS[$slug];
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'integration.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
