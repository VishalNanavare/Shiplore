<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * An AJAX POST that fails CSRF verification — App\Filters\Csrf.
 *
 * The framework's own CSRF filter (system/Filters/CSRF.php) deliberately skips its
 * "redirect back with a flash" recovery for AJAX requests and just re-throws
 * SecurityException — correct for a JSON-native API client, but this app's global
 * ajax-forms.js intercepts EVERY POST <form> and expects either a JSON envelope
 * (App\Filters\AjaxRedirectFilter's shape) or a full HTML document to replace the
 * page with. A thrown SecurityException becomes CI4's plain-HTML error page with no
 * Location header and status >= 400 — exactly what AjaxRedirectFilter::after()
 * passes through UNCHANGED (`$status >= 400 -> return $response`) — so ajax-forms.js
 * falls into its "non-JSON response" branch and document.write()s the raw error page
 * over the form. On screen that reads as "I clicked Create and nothing happened": no
 * toast, no visible error (the report that led here, Vendor\StaffController::create()
 * via shop.shiplore.in/vendor/staff/new).
 *
 * Driven through admin/categories/{id}/activate, the same representative csrf-
 * filtered route AjaxFormTest already uses for the redirect-conversion contract —
 * this test covers the failure side of the same filter chain.
 */
final class AjaxCsrfFailureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        $this->grant(['category.view', 'category.update']);

        Services::injectMock('categoryRepository', new class {
            public function findById(int $id): ?array { return $id === 2 ? ['id' => 2, 'status' => 'inactive'] : null; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $permissions): void
    {
        Services::injectMock('capabilityRepository', new class ($permissions) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function adminSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'Super Admin', 'principal_type' => 'platform'];
    }

    /**
     * The exact failure this filter exists to fix: a WRONG token (a stale form left
     * open past a session/token rotation — the real-world trigger) must come back as
     * the same JSON envelope shape as any other AJAX flash-redirect, not a thrown
     * exception ajax-forms.js cannot parse as JSON.
     */
    public function testAjaxPostWithAnInvalidCsrfTokenReturnsAJsonErrorEnvelope(): void
    {
        $session = service('session')->get() + $this->adminSession();
        $data    = [csrf_token() => 'not-the-real-token'];

        $result = $this->withSession($session)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/categories/2/activate', $data);

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertIsArray($json, 'a CSRF failure over AJAX must still come back as JSON, never a thrown exception');
        $this->assertFalse($json['ok']);
        $this->assertSame('error', $json['type']);
        $this->assertNotEmpty($json['message'], 'ajax-forms.js only shows a toast when message is non-empty (notify() no-ops on empty)');

        // rotateCsrf() on the client reads exactly these two keys to arm the retry —
        // without them the very next submit fails CSRF again with no way to recover.
        $this->assertArrayHasKey('csrf', $json);
        $this->assertArrayHasKey('csrf_name', $json);
        $this->assertNotEmpty($json['csrf']);
    }

    /**
     * The SAME bad token over a plain (non-AJAX) POST must be completely unaffected
     * by this filter — CI4's own redirect-back-with-flash CSRF recovery keeps working
     * exactly as it did before this filter existed. shouldRedirect() is gated on
     * ENVIRONMENT === 'production' (Config\Security), which is not the case here, so
     * this pins the OTHER branch: the filter re-throwing unchanged instead of ever
     * converting a non-AJAX failure into JSON.
     */
    public function testNonAjaxPostWithAnInvalidCsrfTokenIsNeverConvertedToJson(): void
    {
        $session = service('session')->get() + $this->adminSession();
        $data    = [csrf_token() => 'not-the-real-token'];

        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->withSession($session)->post('admin/categories/2/activate', $data);
    }
}
