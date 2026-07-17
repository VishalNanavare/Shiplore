<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * MeController — current-actor endpoints. `GET /api/v1/me/capabilities` returns
 * the resolved capability context (for client-side UI gating). Protected by the
 * `jwtAuth` filter, which populates ScopeContext before this runs.
 *
 * @see docs/architecture/08-PERMISSIONS-ARCHITECTURE.md §6
 */
final class MeController extends BaseApiController
{
    public function capabilities()
    {
        $scope = $this->scope();
        $all   = $scope->all();

        return $this->ok([
            'actor_id'       => $scope->actorId(),
            'principal_type' => $all['principal_type'] ?? null,
            'permissions'    => $scope->permissions(),
            'scopes'         => $scope->scopes(),
            'attributes'     => $all['attributes'] ?? [],
        ]);
    }
}
