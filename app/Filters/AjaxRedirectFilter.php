<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AjaxRedirectFilter
 *
 * System-wide AJAX UX support. Controllers keep doing exactly what they already
 * do — `redirect()->to(...)->with('success'|'error'|'warning', ...)`. When the
 * SAME request arrives over AJAX (the global ajax-forms.js posts every form with
 * `X-Requested-With: XMLHttpRequest`), this `after` filter intercepts the
 * RedirectResponse and converts it into a small JSON envelope the client can act
 * on (show a SweetAlert2 message, rotate the CSRF token, then navigate).
 *
 * Non-AJAX requests are left completely untouched, so:
 *   - browsers with JS disabled keep the normal full-page redirect behaviour, and
 *   - the existing Feature test-suite (which posts WITHOUT the AJAX header) keeps
 *     receiving real 302 redirects and stays green.
 *
 * Registered globally in Config\Filters with `['except' => 'api/*']` so the
 * JSON-native mobile/REST API (which has its own envelope) is never affected.
 */
final class AjaxRedirectFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Only touch AJAX requests …
        if (! $request->isAJAX()) {
            return $response;
        }

        // … that the controller answered with a redirect. Anything else
        // (a JSON response from an existing AJAX endpoint, an HTML page, a
        // 4xx guard) passes straight through unchanged.
        $status   = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');
        if ($status < 300 || $status >= 400 || $location === '') {
            return $response;
        }

        $session   = session();
        $success   = $this->flashString($session->getFlashdata('success'));
        $statusMsg = $this->flashString($session->getFlashdata('status'));  // auth "codes sent" etc.
        $error     = $this->flashString($session->getFlashdata('error'));
        $warning   = $this->flashString($session->getFlashdata('warning'));
        $info      = $this->flashString($session->getFlashdata('info'));

        $errors = $session->getFlashdata('errors');
        if (! is_array($errors)) {
            $errors = [];
        }

        $isError = $error !== '' || $errors !== [];

        if ($isError) {
            $message = $error !== '' ? $error : implode(' ', array_map('strval', $errors));
            $type    = 'error';
        } elseif ($warning !== '') {
            $message = $warning;
            $type    = 'warning';
        } else {
            $message = $success !== '' ? $success : ($statusMsg !== '' ? $statusMsg : $info);
            $type    = 'success';
        }

        // We are delivering these messages in the JSON body right now, so strip
        // them from the session — otherwise they would surface a SECOND time on
        // the client's follow-up GET. (Old input from withInput() is left intact.)
        $session->remove(['success', 'status', 'error', 'warning', 'info', 'errors']);

        $payload = [
            'ok'        => ! $isError,
            'redirect'  => $location,
            'message'   => $message !== '' ? $message : null,
            'type'      => $type,
            'csrf'      => csrf_hash(),
            'csrf_name' => csrf_token(),
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return $response
            ->setStatusCode(200)
            ->removeHeader('Location')
            ->setContentType('application/json')
            ->setBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Flashdata values are usually strings, but a few controllers flash arrays
     * (e.g. implode-able error lists). Normalise to a single display string.
     */
    private function flashString($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(' ', array_map('strval', $value));
        }

        return (string) $value;
    }
}
