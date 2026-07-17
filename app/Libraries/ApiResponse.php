<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * ApiResponse — builds the standard API envelope and maps error codes to HTTP.
 * Pure structure builder; the controller serializes to JSON and sets the status.
 *
 * @see docs/architecture/07-API-ARCHITECTURE.md §2–3
 */
final class ApiResponse
{
    private const STATUS = [
        'BAD_REQUEST'            => 400,
        'UNAUTHENTICATED'        => 401,
        'TOKEN_EXPIRED'          => 401,
        'FORBIDDEN'              => 403,
        'SCOPE_VIOLATION'        => 403,
        'STEP_UP_REQUIRED'       => 403,
        'NOT_FOUND'              => 404,
        'CONFLICT'               => 409,
        'IDEMPOTENT_REPLAY'      => 409,
        'STOCK_CONFLICT'         => 409,
        'VERSION_CONFLICT'       => 409,
        'VALIDATION_ERROR'       => 422,
        'LOCKED'                 => 423,
        'RATE_LIMITED'           => 429,
        'SERVER_ERROR'           => 500,
        'DEPENDENCY_UNAVAILABLE' => 503,
    ];

    public static function success(mixed $data, array $meta = []): array
    {
        return ['success' => true, 'data' => $data, 'meta' => $meta];
    }

    public static function collection(array $items, array $pagination, array $meta = []): array
    {
        $meta['pagination'] = $pagination;

        return ['success' => true, 'data' => $items, 'meta' => $meta];
    }

    public static function error(string $code, string $message, array $details = [], array $meta = []): array
    {
        return [
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message, 'details' => $details ?: new \stdClass()],
            'meta'    => $meta,
        ];
    }

    public static function statusFor(string $code): int
    {
        return self::STATUS[$code] ?? 500;
    }
}
