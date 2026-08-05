<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * CspReportController — collects Content-Security-Policy-Report-Only violation
 * reports (audit M10).
 *
 * The policy has always been report-only (app/Config/ContentSecurityPolicy.php),
 * but with no reportURI a browser had nowhere to SEND a violation, so the file's
 * own documented rollout plan — collect a real traffic day, use it to build the
 * inline-script allow-list, then flip to enforcing — could never start. This
 * controller only COLLECTS; it does not enforce anything and does not touch
 * $reportOnly. Flipping that is a separate, later change (see the config file).
 *
 * Public, unauthenticated POST by design — a browser sends the report with no
 * session and no CSRF token. Deliberately minimal: cap the body, log one line,
 * always return 204 (a browser ignores the response either way).
 */
final class CspReportController extends BaseController
{
    /** Defence in depth — a browser caps the report itself, but never trust the wire. */
    private const MAX_BYTES = 8192;

    public function collect()
    {
        $body = (string) $this->request->getBody();

        // Garbage or an oversized body must not throw or write anything — just 204.
        // request->getJSON() is deliberately NOT used here: it throws on invalid
        // JSON, and a malformed report is exactly the input this has to tolerate.
        if ($body !== '' && strlen($body) <= self::MAX_BYTES) {
            $decoded = json_decode($body, true);
            $report  = is_array($decoded) ? ($decoded['csp-report'] ?? null) : null;
            if (is_array($report)) {
                log_message('notice', sprintf(
                    'csp-violation: directive=%s blocked=%s document=%s',
                    mb_substr((string) ($report['violated-directive'] ?? $report['effective-directive'] ?? ''), 0, 200),
                    mb_substr((string) ($report['blocked-uri'] ?? ''), 0, 200),
                    mb_substr((string) ($report['document-uri'] ?? ''), 0, 200),
                ));
            }
        }

        return $this->response->setStatusCode(204);
    }
}
