<?php

declare(strict_types=1);

use App\Libraries\TokenService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 6 — end-to-end spine test: HTTP route → JwtAuthFilter → controller →
 * ApiResponse envelope. The DB-backed capability loader is mocked, but
 * JwtAuthFilter ALSO re-checks apiAuthRepository->isActive() against a real
 * users row on every request — this file's own original comment ("without a
 * database") predates that check and is no longer accurate; a MinimalSchema
 * users row is what makes this spine test reach its controller at all now.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §6, app/Filters/JwtAuthFilter.php
 */
final class MeCapabilitiesTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    private string $secret = 'feature-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('JWT_SECRET=' . $this->secret);

        // Mock the DB loader so the spine is exercised without a database.
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [[
                    'permissions' => ['order.view.own', 'pos.sell'],
                    'scope_type'  => 'vendor',
                    'scope_id'    => 1,
                    'attributes'  => ['discount_cap' => 10],
                ]];
            }
        });
        $this->ensureUsersTable();
        $this->seedActiveUser(3, 'vendor', 'T');
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        putenv('JWT_SECRET'); // unset
        Services::reset();
        parent::tearDown();
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $result = $this->get('api/v1/me/capabilities');
        $result->assertStatus(401);
        $result->assertJSONFragment(['success' => false]);
    }

    public function testAuthenticatedRequestReturnsCapabilities(): void
    {
        $token = (new TokenService())->issue(['sub' => 3, 'typ' => 'vendor'], 3600, $this->secret, time());

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('api/v1/me/capabilities');

        $result->assertStatus(200);
        $result->assertJSONFragment(['success' => true]);

        $body = json_decode($result->getJSON(), true);
        $this->assertSame(3, $body['data']['actor_id']);
        $this->assertContains('pos.sell', $body['data']['permissions']);
        $this->assertSame([['type' => 'vendor', 'id' => 1]], $body['data']['scopes']);
    }
}
