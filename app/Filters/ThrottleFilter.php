<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ThrottleFilter — fixed-window rate limiting (Phase 17) via CI4's Throttler,
 * keyed by client IP + route. Protects OTP/login/auth endpoints from brute force
 * and abuse. Usage: route filter `throttle:5,60` (5 requests / 60s).
 *
 * @see docs/architecture/41-SECURITY-PERFORMANCE.md
 */
final class ThrottleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $capacity = (int) ($arguments[0] ?? 30);
        $seconds  = (int) ($arguments[1] ?? 60);

        $throttler = service('throttler');
        // cache-safe key (no reserved chars): hash of IP + route path
        $key       = 'throttle_' . md5($request->getIPAddress() . '|' . $request->getUri()->getPath());

        if ($throttler->check($key, $capacity, $seconds) === false) {
            $retry = method_exists($throttler, 'getTokenTime') ? $throttler->getTokenTime() : $seconds;

            return service('response')
                ->setStatusCode(ApiResponse::statusFor('RATE_LIMITED'))
                ->setHeader('Retry-After', (string) $retry)
                ->setJSON(ApiResponse::error('RATE_LIMITED', 'Too many requests. Retry in ' . $retry . 's.'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
