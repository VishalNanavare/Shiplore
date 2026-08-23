<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\CSRF as BaseCsrf;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * Csrf — extends CI4's built-in CSRF filter so a failed AJAX POST degrades
 * gracefully instead of silently.
 *
 * The framework filter's own AJAX branch (system/Filters/CSRF.php:54) deliberately
 * skips its "redirect back with a flash" recovery for AJAX requests and re-throws
 * SecurityException — correct for a JSON-native API consumer, but this app's global
 * ajax-forms.js intercepts EVERY POST <form> (see its header comment) and expects
 * either a JSON envelope or an HTML document to replace the current page with. A
 * thrown SecurityException becomes CI4's plain-HTML error page: no Location header,
 * status >= 400. AjaxRedirectFilter's own after() guard passes that straight through
 * unchanged (`$status >= 400 -> return $response`, app/Filters/AjaxRedirectFilter.php:48),
 * so ajax-forms.js falls into its "non-JSON response" branch and document.write()s the
 * raw error page over the form. On screen that reads as "I clicked Create and nothing
 * happened" — no toast, no visible error at all (the report that led here,
 * Vendor\StaffController::create() via shop.shiplore.in/vendor/staff/new).
 *
 * Fix: for an AJAX request specifically, answer with the SAME JSON envelope shape
 * AjaxRedirectFilter produces ({ok, message, type, csrf, csrf_name}) instead of
 * throwing. ajax-forms.js's existing JSON branch already knows how to show that
 * message AND rotate the form's hidden CSRF field to the fresh hash via
 * rotateCsrf() — so the user sees a clear error and can immediately resubmit, no
 * page reload needed. Non-AJAX requests are untouched: parent::before()'s
 * redirect-back behaviour (production) and its unwrapped throw (elsewhere, e.g.
 * this test suite) are both preserved exactly — only an AJAX request's
 * SecurityException is ever intercepted here.
 */
class Csrf extends BaseCsrf
{
    /** @param list<string>|null $arguments */
    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            return parent::before($request, $arguments);
        } catch (SecurityException $e) {
            if (! $request instanceof IncomingRequest || ! $request->isAJAX()) {
                throw $e;
            }

            return response()
                ->setStatusCode(200)
                ->setJSON([
                    'ok'        => false,
                    'redirect'  => null,
                    'message'   => $e->getMessage(),
                    'type'      => 'error',
                    'csrf'      => csrf_hash(),
                    'csrf_name' => csrf_token(),
                ]);
        }
    }
}
