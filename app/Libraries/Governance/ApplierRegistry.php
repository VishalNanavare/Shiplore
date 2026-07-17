<?php

declare(strict_types=1);

namespace App\Libraries\Governance;

/**
 * ApplierRegistry — maps "entity.action" keys to the domain callable that
 * executes an approved change request. Appliers call the SAME domain service
 * the direct (owner) path uses, so approved and direct writes can never
 * diverge. Features register theirs in Config\Services (X3+).
 */
final class ApplierRegistry
{
    /** @var array<string, callable(array<string,mixed>):mixed> */
    private array $appliers = [];

    /** @param callable(array<string,mixed>):mixed $fn receives the decoded request row */
    public function register(string $key, callable $fn): self
    {
        $this->appliers[$key] = $fn;

        return $this;
    }

    public function get(string $key): ?callable
    {
        return $this->appliers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->appliers[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->appliers);
    }
}
