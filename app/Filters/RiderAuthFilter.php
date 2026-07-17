<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RiderAuthFilter — session guard for the rider web panel. Rider sessions use
 * `rider_id` (separate namespace from the staff `user_id`) to avoid collisions.
 *
 * @see BaseRiderController::requireRider()
 */
final class RiderAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session()->get('rider_id')) {
            return redirect()->to('rider/login')->with('error', 'Please sign in to continue.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
