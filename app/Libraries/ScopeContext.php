<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * ScopeContext — request-scoped holder for the resolved auth context
 * (registered as a shared service). JwtAuthFilter populates it; controllers,
 * policies, and tenant-scoped repositories read from it.
 */
final class ScopeContext
{
    private array $ctx = [];

    public function set(array $ctx): void
    {
        $this->ctx = $ctx;
    }

    public function all(): array
    {
        return $this->ctx;
    }

    public function actorId(): ?int
    {
        return $this->ctx['actor_id'] ?? null;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->ctx['permissions'] ?? [];
    }

    /** @return list<array{type:string,id:?int}> */
    public function scopes(): array
    {
        return $this->ctx['scopes'] ?? [];
    }

    public function isAuthenticated(): bool
    {
        return ! empty($this->ctx['actor_id']);
    }

    public function isPlatform(): bool
    {
        foreach ($this->scopes() as $s) {
            if (($s['type'] ?? null) === 'platform') {
                return true;
            }
        }

        return false;
    }

    /** Vendor ids this actor is scoped to (for query-layer tenant filtering). */
    public function vendorIds(): array
    {
        $ids = [];
        foreach ($this->scopes() as $s) {
            if (($s['type'] ?? null) === 'vendor' && isset($s['id'])) {
                $ids[] = (int) $s['id'];
            }
        }

        return $ids;
    }
}
