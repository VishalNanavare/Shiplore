<?php

declare(strict_types=1);

use App\Libraries\TokenService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 6 — end-to-end spine test: HTTP route → JwtAuthFilter → controller →
 * ApiResponse envelope. The DB-backed capability loader is mocked so this
 * verifies the wiring (filter + service DI + controller), not the DB query.
 *
 * @see docs/architecture/23-AUTH-ACCESS-CONTROL.md §6, app/Filters/JwtAuthFilter.php
 */
final class MeCapabilitiesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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
    }

    protected function tearDown(): void
    {
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
