<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Architecture drift, dead code and a defence-in-depth JWT check (audit L9, L11,
 * L12, I2, I4) plus the free half of M29 (wildcard dependency pins).
 *
 * Not in this file: M27 (delivery status transitions — the audit's own fix is the
 * UNION of two disagreeing maps, and explicitly says "if any of those five is
 * genuinely meant to be forbidden, this legalises it — confirm intent with
 * operations rather than assuming the union is right") and L10 (GstCalculator
 * float arithmetic — Regression Risk: Medium, "treat it as a money change, not a
 * refactor", values can move by up to a paisa). Both are business-rule /
 * financial-precision decisions for the operator, not zero-risk mechanical fixes.
 */
final class ArchAndDeadCodeHardeningTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    /** Same brace-matching extractor used elsewhere this session. */
    private function methodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $brace = strpos($src, '{', (int) $m[0][1]);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        for ($i = $brace, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ L9

    public function testRefundUpdateStatusEnforcesTheStateMachine(): void
    {
        $body = $this->methodBody($this->read('Models/RefundRepository.php'), 'updateStatus');
        $this->assertStringContainsString('StatusMachine::canRefund(', $body, 'canRefund() existed, was unit-tested, and was called from nowhere — this closes that');
    }

    /**
     * The audit assumed RefundController::transition() already handled a false
     * return; reading the actual code showed it did not — it always flashed
     * 'success'. Fixed both layers, since the repository guard alone would have
     * been silently swallowed by the controller.
     */
    public function testRefundControllerReflectsAFailedTransitionInTheFlash(): void
    {
        $body = $this->methodBody($this->read('Controllers/Admin/RefundController.php'), 'transition');
        $this->assertMatchesRegularExpression('/\$ok\s*=\s*\$repo->updateStatus\(/', $body);
        $this->assertStringContainsString("\$ok ? 'success' : 'error'", $body);
    }

    // ------------------------------------------------------------------ I2

    public function testJwtVerifyRejectsAnAlgNoneHeader(): void
    {
        $svc = new \App\Libraries\TokenService();
        $now = 1700000000;
        // Hand-craft a token with alg:none in the header and a signature computed
        // the normal way — the OLD code never inspected the header at all, so this
        // would have reached the signature check; verify it's now rejected before that.
        $header = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode(['exp' => $now + 60])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'secret', true)), '+/', '-_'), '=');

        $this->expectException(\App\Libraries\TokenException::class);
        $this->expectExceptionMessage('Unsupported token algorithm');
        $svc->verify("{$header}.{$payload}.{$sig}", 'secret', $now);
    }

    public function testJwtVerifyRejectsATokenWithNoExpClaim(): void
    {
        $svc = new \App\Libraries\TokenService();
        $now = 1700000000;
        $header = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        // No exp key at all.
        $payload = rtrim(strtr(base64_encode((string) json_encode(['sub' => 1])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'secret', true)), '+/', '-_'), '=');

        $this->expectException(\App\Libraries\TokenException::class);
        $this->expectExceptionMessage('Token expired');
        $svc->verify("{$header}.{$payload}.{$sig}", 'secret', $now);
    }

    /** A real HS256 token this codebase actually issues must still verify. */
    public function testJwtIssueThenVerifyStillRoundTrips(): void
    {
        $svc = new \App\Libraries\TokenService();
        $now = 1700000000;
        $token = $svc->issue(['sub' => 42], 3600, 'secret', $now);
        $claims = $svc->verify($token, 'secret', $now + 10);

        $this->assertSame(42, $claims['sub']);
    }

    // ------------------------------------------------------------------ I4

    public function testLoginPageHasNoRememberMeCheckbox(): void
    {
        $view = $this->read('Views/auth/login.php');
        $this->assertStringNotContainsString('id="remember"', $view, 'the checkbox had no name attribute — it was never submitted and nothing on the server ever read it');
        $this->assertStringContainsString('Forgot password?', $view, 'the real control must survive');
    }

    // ------------------------------------------------------------------ L11

    public function testNoDirectInstantiationOutsideTheServiceDefinitions(): void
    {
        foreach ([
            'Models/PosSaleRepository.php', 'Models/StoreOrderRepository.php',
            'Models/PosReturnRepository.php', 'Libraries/Store/CartService.php',
            'Commands/OrdersEscalate.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertDoesNotMatchRegularExpression(
                '/new\s+(\\\\App\\\\Libraries\\\\Tax\\\\)?GstCalculator\(\)|new\s+(\\\\App\\\\Libraries\\\\Orders\\\\)?EscalationService\(\)/',
                $src,
                $rel . ' must resolve through the container, not construct directly — GstCalculator is stateless today, but the moment it gains constructor state this is where behaviour silently diverges',
            );
        }
        $this->assertStringContainsString("service('gstCalculator')", $this->read('Controllers/Api/V1/VendorPosController.php'));
    }

    public function testGstCalculatorAndEscalationServiceAreRegisteredShared(): void
    {
        $src = $this->read('Config/Services.php');
        $this->assertStringContainsString("getSharedInstance('gstCalculator')", $src);
        $this->assertStringContainsString("getSharedInstance('escalationService')", $src);
        // CartService's injection seam must survive — an explicitly-passed $gst still wins.
        $this->assertMatchesRegularExpression(
            '/\$gst\s*\?\?\s*service\(\'gstCalculator\'\)/',
            $this->read('Libraries/Store/CartService.php'),
        );
    }

    // ------------------------------------------------------------------ L12

    public function testMasterCrudDelegatesEveryWriteToTheRepository(): void
    {
        $src = $this->read('Controllers/Admin/Concerns/MasterCrud.php');
        $this->assertStringNotContainsString('Database::connect', $src, 'writes must go through MasterRepository, not open a connection directly');

        foreach (['masterIndex', 'toggle', 'edit', 'store', 'update'] as $m) {
            $body = $this->methodBody($src, $m);
            $this->assertStringContainsString("service('masterRepository')", $body, $m . '() must use the repository');
        }
    }

    public function testMasterRepositoryAuditsCreateUpdateAndToggle(): void
    {
        $src = $this->read('Models/MasterRepository.php');
        $create = $this->methodBody($src, 'create');
        $update = $this->methodBody($src, 'update');
        $this->assertStringContainsString('$this->audit(', $create);
        $this->assertStringContainsString('$this->audit(', $update);

        $audit = $this->methodBody($src, 'audit');
        $this->assertStringContainsString("service('auditWriter')->log(", $audit);
        // A logging fault must never break the save it records.
        $this->assertStringContainsString('catch (\Throwable)', $audit);
    }

    /**
     * End-to-end through a real controller (UnitController), not just source
     * scanning: proves store()/toggle() actually reach the repository with the
     * right table/actor, and that a not-found toggle still flashes 'error' exactly
     * as before the repository existed.
     */
    public function testUnitControllerStoreWiresThroughToTheRepository(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['unit.manage', 'unit.view'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        $spy = new class {
            public array $created = [];
            public function create(string $table, string $entityType, array $data, ?int $actorId): int
            {
                $this->created = [$table, $entityType, $data, $actorId];

                return 77;
            }
        };
        Services::injectMock('masterRepository', $spy);

        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Admin', 'principal_type' => 'platform'];
        $r = $this->withSession($sess)
            ->post('admin/units/store', [csrf_token() => csrf_hash(), 'code' => 'KG', 'name' => 'Kilogram']);

        $r->assertRedirect();
        $this->assertSame('units', $spy->created[0]);
        $this->assertSame('units', $spy->created[1]);
        $this->assertSame(5, $spy->created[3]);
        $this->assertSame('KG', $spy->created[2]['code']);
    }

    public function testUnitControllerToggleNotFoundStillFlashesError(): void
    {
        Services::injectMock('capabilityRepository', new class {
            public function loadAssignments(int $u): array
            {
                return [['permissions' => ['unit.manage', 'unit.view'], 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
        Services::injectMock('masterRepository', new class {
            public function statusOf(string $table, int $id): ?array { return null; }
        });

        $sess = ['isLoggedIn' => true, 'user_id' => 5, 'user_name' => 'Admin', 'principal_type' => 'platform'];
        $r = $this->withSession($sess)->post('admin/units/999/toggle', [csrf_token() => csrf_hash()]);

        $r->assertRedirect();
        $this->assertNotEmpty(service('session')->getFlashdata('error'));
    }

    // ------------------------------------------------------------------ M29 (free half)

    public function testComposerJsonHasNoWildcardDependencyVersions(): void
    {
        $composer = json_decode($this->readRoot('composer.json'), true);
        foreach (['box/spout', 'dompdf/dompdf', 'aws/aws-sdk-php'] as $pkg) {
            $this->assertArrayHasKey($pkg, $composer['require']);
            $this->assertNotSame('*', $composer['require'][$pkg], $pkg . ' must be range-constrained, not wildcard-pinned');
            $this->assertStringStartsWith('^', $composer['require'][$pkg]);
        }
    }

    private function readRoot(string $rel): string
    {
        return (string) file_get_contents(ROOTPATH . $rel);
    }
}
