<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\ScopeContext;
use CodeIgniter\RESTful\ResourceController;

/**
 * BaseApiController — thin base for all API controllers: standard envelope
 * (via ApiResponse), error→HTTP mapping, and access to the resolved
 * ScopeContext. Controllers stay thin: validate → service → respond.
 *
 * @see docs/architecture/07-API-ARCHITECTURE.md, 22 §4
 */
abstract class BaseApiController extends ResourceController
{
    protected function scope(): ScopeContext
    {
        return service('scopeContext');
    }

    /** Merged input (form + JSON body) — mobile apps send JSON. @return array<string,mixed> */
    protected function input(): array
    {
        $post = (array) $this->request->getPost();
        if (str_contains($this->request->getHeaderLine('Content-Type'), 'application/json')) {
            return array_merge($post, (array) ($this->request->getJSON(true) ?? []));
        }
        return $post;
    }

    /** Authenticated user id from the resolved scope (JWT sub). */
    protected function userId(): int
    {
        return (int) $this->scope()->actorId();
    }

    protected function ok(mixed $data, array $meta = [])
    {
        return $this->respond(ApiResponse::success($data, $meta), 200);
    }

    protected function created(mixed $data, array $meta = [])
    {
        return $this->respond(ApiResponse::success($data, $meta), 201);
    }

    protected function collection(array $items, array $pagination, array $meta = [])
    {
        return $this->respond(ApiResponse::collection($items, $pagination, $meta), 200);
    }

    /** Standard error envelope with code→HTTP mapping. */
    protected function failWith(string $code, string $message, array $details = [], array $meta = [])
    {
        return $this->respond(
            ApiResponse::error($code, $message, $details, $meta),
            ApiResponse::statusFor($code),
        );
    }
}
