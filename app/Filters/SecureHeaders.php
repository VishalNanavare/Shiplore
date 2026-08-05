<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\SecureHeaders as BaseSecureHeaders;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * SecureHeaders — extends CI4's built-in filter with the headers that, until now,
 * existed ONLY in the project-root .htaccess (audit L13): the clickjacking CSP and
 * Permissions-Policy. Filters.php already flagged the hazard on the 'secureheaders'
 * global — "it stops applying the moment the DocumentRoot moves to public/ (the
 * planned change) or the file is lost" — this carries them into the app so they
 * travel with the code instead of one specific vhost file.
 *
 * Also adds Cross-Origin-Opener-Policy and Cross-Origin-Resource-Policy, which exist
 * in neither place today. COEP is deliberately OMITTED: `require-corp` would break
 * the Firebase and Google Maps loads app/Config/ContentSecurityPolicy.php allows.
 *
 * Values match .htaccess exactly, and Apache's `Header always set` still wins where
 * both set the same header (Apache processes the response after PHP hands off), so
 * effective behaviour today is unchanged — this only matters once .htaccess is gone.
 *
 * Not touched here: .htaccess's Referrer-Policy is "strict-origin-when-cross-origin",
 * CI4's built-in default (parent::after()) is "same-origin" — a pre-existing
 * discrepancy, invisible today because Apache always wins, that would become live
 * (and slightly LESS strict) the moment .htaccess stops applying. Out of scope for
 * this filter, which only adds the two headers .htaccess uniquely carries plus
 * COOP/CORP; flagged for whoever executes the DocumentRoot move.
 */
class SecureHeaders extends BaseSecureHeaders
{
    /** @param list<string>|null $arguments */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response = parent::after($request, $response, $arguments);

        // Same values as .htaccess's <IfModule mod_headers.c> block.
        $response->setHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $response->setHeader('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()');

        // Not in .htaccess today — new defence in depth. COOP: without it, a page
        // opened via window.open from a hostile origin retains a window.opener
        // handle back into the admin portal. PayU and Razorpay here are full-page
        // redirects, not window.opener flows, so same-origin is safe to ship.
        $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');
        // CORP: stops other origins embedding our resources (e.g. /media/*). Use
        // 'cross-origin' instead if a partner feed/site is ever found hotlinking
        // our images — none is known today.
        $response->setHeader('Cross-Origin-Resource-Policy', 'same-site');

        return $response;
    }
}
