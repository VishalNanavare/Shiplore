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
            'provider' => 'smtp', 'label' => 'Email', 'icon' => 'bi-envelope',
            'blurb'    => 'Transactional email — order updates, password resets, invoices. Use "sendmail" when outbound SMTP is blocked or intercepted by the host.',
            'fields'   => [
                ['protocol', 'Transport', 'select', false, ['smtp', 'sendmail', 'mail']],
                // Host/credentials only apply to the smtp transport; sendmail/mail hand
                // off to the local MTA, so these stay optional and Mailer::configured()
                // enforces what each transport actually needs.
                ['host', 'SMTP Host', 'text', false],
                ['port', 'Port', 'text', false],
                ['username', 'Username', 'text', false],
                ['password', 'Password', 'password', false],
                ['encryption', 'Encryption', 'select', false, ['tls', 'ssl', 'none']],
                ['from_email', 'From Email', 'text', true],
                ['from_name', 'From Name', 'text', false],
                // Where "Test connection" delivers. Kept as a saved field so the operator
                // does not retype it on every attempt; from_email is a poor default
                // because no_reply@ mailboxes are typically never read.
                ['test_to', 'Send test email to', 'text', false],
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

        // Stored values are needed BEFORE building the new config: secret fields render
        // empty in the form (their values must not reach the page source), so a blank
        // secret means "keep what is saved", not "clear it". Non-secret fields keep the
        // old semantics — blank clears — pinned by test so this rule cannot widen.
        $stored = service('integrationRepository')->config($spec['provider']);

        $config = [];
        foreach ($spec['fields'] as $f) {
            [$key, , $type] = $f;
            $posted = (string) $this->request->getPost($key);

            // Passwords lose ALL whitespace, not just the ends — esection's lesson.
            // Google shows an App Password as four spaced groups ("abcd efgh ijkl mnop")
            // purely for readability; pasted as-is it is a 19-character credential that
            // fails with the same 535 as a wrong password, and nothing on any screen can
            // say why. No provider issues a credential with meaningful internal spaces,
            // so the strip can only turn a guaranteed failure into the intended value.
            // The service-account JSON is exempt — its whitespace is meaningful.
            $posted = $type === 'password'
                ? (string) preg_replace('/\s+/u', '', $posted)
                : trim($posted);

            if ($posted === '' && $this->isSecretField($f) && trim((string) ($stored[$key] ?? '')) !== '') {
                $config[$key] = (string) $stored[$key];

                continue;
            }

            // Selects only accept their declared options; anything else keeps the stored
            // value. Without this, a missing or empty "protocol" persisted '' — which
            // every reader coerces to smtp — so a truncated POST or a stale form could
            // flip the transport back while reporting "settings saved".
            $options = $f[4] ?? [];
            if ($type === 'select' && ! in_array($posted, $options, true)) {
                $config[$key] = (string) ($stored[$key] ?? '');

                continue;
            }

            $config[$key] = $posted;
        }

        // The write's outcome decides the message. This used to flash "settings saved."
        // unconditionally, so a failed write (poisoned JSON, schema drift, vanished row)
        // was indistinguishable from success — during a live outage that reads as "the
        // save keeps not taking effect" with no clue where it is lost.
        $ok = service('integrationRepository')->upsert($spec['provider'], $config, 'connected', (int) session()->get('user_id'));
        if (! $ok) {
            log_message('error', 'IntegrationController: the ' . $spec['provider'] . ' settings write failed — see preceding repository log lines.');

            return redirect()->to('admin/integrations/' . $slug)->with(
                'error',
                $spec['label'] . ' settings could not be saved — the database write failed. Nothing was changed; the previous settings still apply.',
            );
        }

        return redirect()->to('admin/integrations/' . $slug)->with('success', $spec['label'] . ' settings saved.');
    }

    /**
     * Compose a test email — the operator supplies recipient, subject and body.
     *
     * "Test connection" sends fixed content to a saved address, which proves the
     * transport accepted a message and nothing more. During an outage the questions
     * that actually arise are narrower than that: does a real subject line survive,
     * does the body arrive readable, does delivery to THIS mailbox work rather than
     * the no_reply@ one in settings. This screen answers all three.
     */
    public function compose()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $spec   = $this->spec('email');
        $config = service('integrationRepository')->config($spec['provider']);

        return view('admin/integrations/test_email', [
            'title'     => 'Send a test email · Admin',
            'pageTitle' => 'Send a test email',
            'active'    => 'int-email',
            'userName'  => session()->get('user_name') ?: 'User',
            'spec'      => $spec,
            'config'    => $config,
            'to'        => trim((string) ($config['test_to'] ?? '')) ?: trim((string) ($config['from_email'] ?? '')),
            'transport' => trim((string) ($config['protocol'] ?? '')) ?: 'smtp',
        ]);
    }

    /**
     * Send what the operator typed.
     *
     * Three containment decisions, all deliberate. An authenticated admin who can send
     * an arbitrary subject and body to an arbitrary address FROM the business domain —
     * passing that domain's SPF and DKIM — holds a better phishing primitive than most
     * attackers can build, so the feature is kept to exactly what a delivery test needs:
     *
     *   - the body is escaped, so typed input can never become live markup. Authoring
     *     HTML is not part of the job; confirming a message arrives readable is.
     *   - the subject is stripped of CR/LF. A newline in a subject is header injection,
     *     which is how a Bcc gets added to a message nobody meant to copy.
     *   - a footer names the message as an admin test and carries the sending user id,
     *     so it is neither deniable nor anonymous. The operator's own subject and body
     *     are left untouched — the footer is additive, because rewriting the subject
     *     would defeat the one thing they came here to check.
     */
    public function sendTest(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $back    = 'admin/integrations/email/compose';
        $spec    = $this->spec('email');
        $config  = service('integrationRepository')->config($spec['provider']);

        $to      = trim((string) $this->request->getPost('to'));
        $subject = trim((string) $this->request->getPost('subject'));
        $message = (string) $this->request->getPost('message');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid email address to send to.');
        }
        if ($subject === '') {
            return redirect()->to($back)->withInput()->with('error', 'Enter a subject.');
        }
        if (trim($message) === '') {
            return redirect()->to($back)->withInput()->with('error', 'Enter a message to send.');
        }

        // CR/LF out of the subject before it reaches any header.
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));

        $mailer    = service('mailer');
        $transport = trim((string) ($config['protocol'] ?? '')) ?: 'smtp';

        // Same pre-flight the fixed-content test does: a 465/tls pair does not fail
        // with a protocol error, it hangs until the socket times out, which reads as a
        // blocked host and sends the operator after the wrong problem.
        if ($mailer->hasCryptoMismatch()) {
            return redirect()->to($back)->withInput()->with(
                'error',
                'Port ' . (int) ($config['port'] ?? 0) . ' requires encryption "' . $mailer->impliedCrypto()
                . '", but "' . ($config['encryption'] ?? '') . '" is set. Port 465 is implicit SSL; port 587 is STARTTLS.',
            );
        }

        $uid   = (int) session()->get('user_id');
        $stamp = date('Y-m-d H:i:s');
        $body  = '<div>' . nl2br(esc($message)) . '</div>'
            . '<hr><p style="color:#777;font-size:12px">'
            . 'This is a test message sent from the ' . esc(service('settingsRepository')->brandName())
            . ' admin panel by user #' . $uid . ' at ' . esc($stamp) . ' UTC, via "' . esc($transport) . '".'
            . '</p>';

        $ok = $mailer->send($to, $subject, $body);
        service('integrationRepository')->setStatus($spec['provider'], $ok ? 'connected' : 'error', $uid);

        if ($ok) {
            return redirect()->to($back)->with(
                'success',
                'Sent to ' . $to . ' via "' . $transport . '". Check that inbox and its spam folder — '
                . 'a delivered message is the only proof the transport works.',
            );
        }

        // diagnose() opens its own socket and keeps the errno/errstr CodeIgniter throws
        // away, so the operator is told whether the port is blocked, the name does not
        // resolve, TLS fails, or the credentials are wrong — rather than being handed
        // "your server might not be configured", which describes all four.
        // Asked of EVERY transport, not just smtp. sendmail fails silently inside
        // CodeIgniter when popen is unavailable, so the operator who takes our advice to
        // switch would otherwise land straight back in the dark.
        $detail = $mailer->diagnose();

        return redirect()->to($back)->withInput()->with(
            'error',
            'Could not send via "' . $transport . '". ' . ($mailer->lastError() ?: 'No detail was reported.')
            . ($detail !== '' ? ' Diagnosis: ' . $detail : ''),
        );
    }

    /**
     * A field whose saved value must never reach the browser again.
     *
     * The password type covers most of them; service_account_json is a textarea — it has
     * to be, it is a JSON document — but holds a Firebase PRIVATE KEY, the most sensitive
     * value on any of these screens.
     *
     * @param array{0:string,1:string,2:string,3:bool,4?:array} $field
     */
    private function isSecretField(array $field): bool
    {
        return $field[2] === 'password' || $field[0] === 'service_account_json';
    }

    public function test(string $slug): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $spec   = $this->spec($slug);
        $config = service('integrationRepository')->config($spec['provider']);

        // Save and Test are two submit buttons on ONE form, and Test reads the SAVED
        // config — it never sees the dropdown. So changing Transport and clicking Test
        // silently tests the old transport and blames it, with nothing on screen hinting
        // the change was ignored. That cost a real diagnosis session: after proving the
        // host intercepts SMTP and that sendmail was the fix, the next test still said
        // 'Could not send via "smtp"', because the dropdown had never been saved.
        $postedProtocol = trim((string) $this->request->getPost('protocol'));
        $savedProtocol  = trim((string) ($config['protocol'] ?? ''));
        // Compared against the EFFECTIVE saved transport: an empty saved value runs as
        // smtp (Mailer's default), so a fresh install with the dropdown on sendmail would
        // silently test smtp while the screen says sendmail — the exact shape of the live
        // incident. The first version skipped the check when nothing was saved, on the
        // reasoning that the missing-fields error covers it; an audit ranked that hole
        // first among this guard's gaps.
        if ($postedProtocol !== '' && $postedProtocol !== ($savedProtocol !== '' ? $savedProtocol : 'smtp')) {
            return redirect()->to('admin/integrations/' . $slug)->with(
                'error',
                $savedProtocol === ''
                    ? 'No transport has been saved yet, so the test would run with the default "smtp" while the'
                        . ' screen shows "' . $postedProtocol . '". Click "Save settings" first, then test.'
                    : 'Transport is set to "' . $postedProtocol . '" on screen, but "' . $savedProtocol
                        . '" is what has been saved — and the test uses the saved settings. Click "Save settings" first,'
                        . ' then test.',
            );
        }

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

        // The email provider is proven by actually sending — not by checking that the
        // fields are non-empty. The recipient is the "Send test email to" box so the
        // operator can direct it at a mailbox they actually read: defaulting to
        // from_email meant testing a no_reply@ address nobody opens, which proves the
        // transport accepted the message but not that it was delivered.
        if ($spec['provider'] === 'smtp') {
            $to = trim((string) $this->request->getPost('test_to'))
                ?: trim((string) ($config['test_to'] ?? ''))
                ?: trim((string) ($config['from_email'] ?? ''))
                ?: trim((string) ($config['username'] ?? ''));

            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return redirect()->to('admin/integrations/' . $slug)
                    ->with('error', 'Enter a valid email address to send the test to.');
            }

            $brand    = service('settingsRepository')->brandName();
            $transport = trim((string) ($config['protocol'] ?? '')) ?: 'smtp';
            $stamp    = date('Y-m-d H:i:s');

            $mailer = service('mailer');

            // Caught BEFORE attempting a send. A 465/tls pair does not fail with a
            // protocol error — it hangs until the socket times out, which reads as "the
            // host is blocking us" and sends the operator chasing the wrong thing.
            if ($mailer->hasCryptoMismatch()) {
                service('integrationRepository')->setStatus($spec['provider'], 'error', (int) session()->get('user_id'));

                return redirect()->to('admin/integrations/' . $slug)->with(
                    'error',
                    'Port ' . (int) ($config['port'] ?? 0) . ' requires encryption "'
                    . $mailer->impliedCrypto() . '", but "' . ($config['encryption'] ?? '')
                    . '" is set. Port 465 is implicit SSL; port 587 is STARTTLS. Fix the pair and test again.',
                );
            }

            $ok     = $mailer->send(
                $to,
                $brand . ' email test — ' . $stamp,
                '<p>This is a test email from ' . esc($brand) . '.</p>'
                . '<p>If you are reading this, outbound email is working.</p>'
                . '<ul><li>Transport: <b>' . esc($transport) . '</b></li>'
                . '<li>Sent at: ' . esc($stamp) . ' UTC</li></ul>',
            );
            service('integrationRepository')->setStatus($spec['provider'], $ok ? 'connected' : 'error', (int) session()->get('user_id'));

            return redirect()->to('admin/integrations/' . $slug)->with(
                $ok ? 'success' : 'error',
                $ok
                    ? 'Test email sent to ' . $to . ' via "' . $transport . '" — check that inbox (and spam) to confirm delivery.'
                    // Show the REASON, not a pointer to a logfile. On shared hosting
                    // an operator frequently cannot read writable/logs, so "see the log"
                    // ends the diagnosis instead of advancing it — which is how an email
                    // outage here went several rounds without anyone seeing the error.
                    // Mailer::lastError() is redacted of credentials before it gets here,
                    // and diagnose() adds what the framework discards — the network-level
                    // reason for smtp, the local-handoff reason for sendmail.
                    : 'Could not send via "' . $transport . '". ' . ($mailer->lastError() ?: 'No detail was reported.')
                        . (($d = $mailer->diagnose()) !== '' ? ' Diagnosis: ' . $d : '')
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
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'integration.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
