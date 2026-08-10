<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * Phase 9 — mobile API: OTP/password login -> JWT, and the JWT-scoped Customer /
 * Vendor / Delivery-Boy endpoints. The real JwtAuthFilter runs; tokens are
 * minted with TokenService and capability loading is mocked. Note this file's
 * OWN original comment ("no DB") is stale: JwtAuthFilter also re-checks
 * apiAuthRepository->isActive() on every request, unmocked here on purpose — a
 * real (SQLite, MinimalSchema) users row, not a mock, backs that check.
 */
final class MobileApiTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => [], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        $this->ensureUsersTable();
        $this->seedActiveUser(2, 'customer');
        $this->seedActiveUser(5, 'rider');
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        Services::reset();
        parent::tearDown();
    }

    private function token(int $userId, string $typ): string
    {
        $secret = (string) (getenv('JWT_SECRET') ?: env('jwt.secret', 'dev-insecure-secret-change-me'));

        return service('tokenService')->issue(['sub' => $userId, 'typ' => $typ, 'name' => 'Test'], 3600, $secret, time());
    }

    /** @return array<string,string> */
    private function bearer(int $userId, string $typ): array
    {
        return ['Authorization' => 'Bearer ' . $this->token($userId, $typ)];
    }

    // ---- Auth ----
    public function testOtpRequestAndVerifyReturnsToken(): void
    {
        Services::injectMock('apiAuthRepository', new class {
            public function findByIdentifier(string $id): ?array { return ['id' => 9, 'name' => 'Aarav', 'principal_type' => 'customer', 'email' => $id, 'phone' => '9', 'status' => 'active', 'password_hash' => null]; }
        });
        Services::injectMock('otpService', new class {
            public function issue(string $id, string $p = 'verify'): string { return '111111'; }
            public function verify(string $id, string $code, string $p = 'verify'): bool { return $code === '111111'; }
        });

        $req = $this->post('api/v1/auth/otp/request', ['identifier' => 'a@b.c']);
        $req->assertStatus(200);
        $this->assertSame('111111', $req->getJSON() ? json_decode($req->getJSON(), true)['meta']['dev_code'] : null);

        $ver = $this->post('api/v1/auth/otp/verify', ['identifier' => 'a@b.c', 'code' => '111111']);
        $ver->assertStatus(200);
        $this->assertArrayHasKey('token', json_decode($ver->getJSON(), true)['data']);
    }

    public function testLoginRejectsBadPassword(): void
    {
        Services::injectMock('apiAuthRepository', new class {
            public function findByIdentifier(string $id): ?array { return ['id' => 9, 'name' => 'X', 'principal_type' => 'customer', 'email' => $id, 'phone' => '9', 'status' => 'active', 'password_hash' => password_hash('right', PASSWORD_BCRYPT)]; }
        });
        $this->post('api/v1/auth/login', ['identifier' => 'a@b.c', 'password' => 'wrong'])->assertStatus(401);
    }

    public function testUnauthenticatedRiderIs401(): void
    {
        $this->get('api/v1/rider/me')->assertStatus(401);
    }

    // ---- M6: JWT password-binding claim ----
    //
    // These stamp their own JWT_SECRET (like MeCapabilitiesTest does) rather than
    // relying on the getenv()/env() fallback the shared bearer() helper uses — that
    // fallback resolves to TokenService::INSECURE_DEFAULT when no test env has
    // jwt.secret configured, which makes secret() throw for every test in this file
    // (a pre-existing environment gap, not specific to these three). Setting and
    // restoring it locally keeps that gap from being masked or spread by these tests.

    /** @return array<string,string> */
    private function bearerWithPwdClaim(string $secret, int $userId, string $typ, ?string $pwdClaim): array
    {
        $claims = ['sub' => $userId, 'typ' => $typ, 'name' => 'Test'];
        if ($pwdClaim !== null) {
            $claims['pwd'] = $pwdClaim;
        }
        $token = service('tokenService')->issue($claims, 3600, $secret, time());

        return ['Authorization' => 'Bearer ' . $token];
    }

    /** A token minted before this M6 deploy carries no `pwd` claim at all — it must keep working until it naturally expires. */
    public function testTokenWithNoPwdClaimStillAuthenticates(): void
    {
        $secret = 'm6-test-secret';
        putenv('JWT_SECRET=' . $secret);
        try {
            $this->riderMock();
            Services::injectMock('apiAuthRepository', new class {
                public function isActive(int $id): bool { return true; }
                public function findById(int $id): ?array { return ['id' => $id, 'password_hash' => password_hash('current', PASSWORD_BCRYPT)]; }
            });

            $this->withHeaders($this->bearerWithPwdClaim($secret, 5, 'rider', null))
                ->get('api/v1/rider/me')
                ->assertStatus(200);
        } finally {
            putenv('JWT_SECRET');
        }
    }

    /** The claim matches the account's current password_hash — issued and never invalidated. */
    public function testTokenWithMatchingPwdClaimAuthenticates(): void
    {
        $secret = 'm6-test-secret';
        putenv('JWT_SECRET=' . $secret);
        try {
            $this->riderMock();
            $hash = password_hash('current', PASSWORD_BCRYPT);
            Services::injectMock('apiAuthRepository', new class ($hash) {
                public function __construct(private string $hash) {}
                public function isActive(int $id): bool { return true; }
                public function findById(int $id): ?array { return ['id' => $id, 'password_hash' => $this->hash]; }
            });

            $claim = \App\Libraries\TokenService::pwdClaim($hash);
            $this->withHeaders($this->bearerWithPwdClaim($secret, 5, 'rider', $claim))
                ->get('api/v1/rider/me')
                ->assertStatus(200);
        } finally {
            putenv('JWT_SECRET');
        }
    }

    /** The account's password changed since the token was minted — the stale claim must be rejected. */
    public function testTokenWithStalePwdClaimIsRejected(): void
    {
        $secret = 'm6-test-secret';
        putenv('JWT_SECRET=' . $secret);
        try {
            $this->riderMock();
            $currentHash = password_hash('new-password', PASSWORD_BCRYPT);
            Services::injectMock('apiAuthRepository', new class ($currentHash) {
                public function __construct(private string $hash) {}
                public function isActive(int $id): bool { return true; }
                public function findById(int $id): ?array { return ['id' => $id, 'password_hash' => $this->hash]; }
            });

            $staleClaim = \App\Libraries\TokenService::pwdClaim(password_hash('old-password', PASSWORD_BCRYPT));
            $this->withHeaders($this->bearerWithPwdClaim($secret, 5, 'rider', $staleClaim))
                ->get('api/v1/rider/me')
                ->assertStatus(401);
        } finally {
            putenv('JWT_SECRET');
        }
    }

    // ---- Delivery Boy app ----
    private function riderMock(bool $isRider = true): object
    {
        $m = new class ($isRider) {
            public bool $isRider;
            public array $calls = [];
            public function __construct(bool $r) { $this->isRider = $r; }
            public function profile(int $u): ?array { return $this->isRider ? ['id' => 1, 'availability' => 'available', 'status' => 'active', 'name' => 'Ravi', 'phone' => '9'] : null; }
            public function setAvailability(int $u, string $s): bool { $this->calls[] = "avail:$s"; return true; }
            public function assignments(int $u, ?string $s = null): array { return [['delivery_id' => 1, 'order_no' => 'ORD-1', 'status' => 'assigned', 'cod_amount' => '999.00', 'pickup' => [], 'drop' => []]]; }
            public function assignment(int $id, int $u): ?array { return $id === 1 ? ['delivery_id' => 1, 'items' => []] : null; }
            public function updateStatus(int $id, int $u, string $s, ?string $reason = null, ?float $lat = null, ?float $lng = null): bool { return $id === 1; }
            public function recordPod(int $id, int $u, string $t, string $r): bool { return $id === 1; }
            public function recordCod(int $id, int $u): bool { return $id === 1; }
            public function earnings(int $u): array { return ['enabled' => true, 'total' => '120.00', 'deliveries' => 3]; }
            public function earningsDetail(int $u): array { return ['today' => '40.00', 'week' => '80.00', 'total' => '120.00', 'cash_in_hand' => '0.00', 'entries' => []]; }
            public function ownsAcceptedDelivery(int $deliveryId, int $u): bool { return $deliveryId === 1; }
        };
        Services::injectMock('riderRepository', $m);

        return $m;
    }

    public function testRiderMe(): void
    {
        $this->riderMock();
        $r = $this->withHeaders($this->bearer(5, 'rider'))->get('api/v1/rider/me');
        $r->assertStatus(200);
        $this->assertStringContainsString('available', (string) $r->getJSON());
    }

    public function testNonRiderForbidden(): void
    {
        $this->riderMock(false);
        $this->withHeaders($this->bearer(5, 'customer'))->get('api/v1/rider/me')->assertStatus(403);
    }

    public function testRiderAssignedOrders(): void
    {
        $this->riderMock();
        $r = $this->withHeaders($this->bearer(5, 'rider'))->get('api/v1/rider/orders?status=active');
        $r->assertStatus(200);
        $this->assertStringContainsString('cod_amount', (string) $r->getJSON());
    }

    public function testRiderStatusUpdate(): void
    {
        $this->riderMock();
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/status', ['status' => 'picked_up'])->assertStatus(200);
    }

    public function testRiderStatusValidation(): void
    {
        $this->riderMock();
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/status', ['status' => 'teleported'])->assertStatus(422);
    }

    public function testRiderFailedRequiresReason(): void
    {
        $this->riderMock();
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/status', ['status' => 'failed'])->assertStatus(422);
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/status', ['status' => 'failed', 'reason' => 'customer_unreachable'])->assertStatus(200);
    }

    public function testRiderProofOfDelivery(): void
    {
        $this->riderMock();
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/pod', ['type' => 'otp', 'ref' => '1234'])->assertStatus(200);
    }

    public function testRiderCodCollection(): void
    {
        $this->riderMock();
        $this->withHeaders($this->bearer(5, 'rider'))->post('api/v1/rider/orders/1/cod', [])->assertStatus(200);
    }

    public function testRiderEarnings(): void
    {
        $this->riderMock();
        $r = $this->withHeaders($this->bearer(5, 'rider'))->get('api/v1/rider/earnings');
        $r->assertStatus(200);
        $this->assertStringContainsString('120.00', (string) $r->getJSON());
    }

    // ---- Vendor app ----
    public function testVendorDashboard(): void
    {
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1]; }
        });
        Services::injectMock('vendorDashboardRepository', new class {
            public function metrics(int $v): array { return ['orders' => 5]; }
            public function recentOrders(int $v, int $l = 8): array { return []; }
        });
        $this->withHeaders($this->bearer(2, 'vendor'))->get('api/v1/vendor/dashboard')->assertStatus(200);
    }

    public function testVendorOrderStatusRejectsIllegalTransition(): void
    {
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return ['id' => 1]; }
        });
        Services::injectMock('vendorOrderRepository', new class {
            public function findSubOrder(int $id, int $v): ?array { return ['id' => $id, 'status' => 'delivered', 'shop_id' => 1]; }
            public function updateSubOrderStatus(int $id, int $v, string $s): bool { return true; }
        });
        // delivered -> accepted is illegal
        $this->withHeaders($this->bearer(2, 'vendor'))->post('api/v1/vendor/orders/1/status', ['status' => 'accepted'])->assertStatus(409);
    }

    public function testNonVendorForbidden(): void
    {
        Services::injectMock('vendorAccountRepository', new class {
            public function findByOwnerUserId(int $u): ?array { return null; }
            public function findStaffVendor(int $u): ?array { return null; }
        });
        $this->withHeaders($this->bearer(2, 'customer'))->get('api/v1/vendor/dashboard')->assertStatus(403);
    }

    // ---- Customer app (public browse) ----
    public function testCustomerHomePublic(): void
    {
        Services::injectMock('storeCatalogRepository', new class {
            public function categories(int $l = 8): array { return []; }
            public function products(array $o = []): array { return []; }
            public function categoryTreeWithCounts(array $opts = []): array { return []; }
        });
        Services::injectMock('storeShopRepository', new class {
            public function nearby(?float $a, ?float $b, int $l = 6): array { return []; }
            public function nearbyShopIds(?float $lat, ?float $lng, int $limit = 200): array { return []; }
        });
        Services::injectMock('bannerRepository', new class {
            public function activeForHome(int $limit = 6): array { return []; }
        });
        $this->get('api/v1/customer/home?lat=19.1&lng=72.8')->assertStatus(200);
    }
}
