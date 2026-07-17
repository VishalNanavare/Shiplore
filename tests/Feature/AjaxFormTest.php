<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * System-wide AJAX form UX — App\Filters\AjaxRedirectFilter.
 *
 * Contract:
 *  - A normal (non-AJAX) POST keeps the existing full-page redirect behaviour.
 *  - The SAME POST with `X-Requested-With: XMLHttpRequest` is transparently
 *    converted into a JSON envelope {ok, redirect, message, type, csrf} so the
 *    global ajax-forms.js can show SweetAlert2 + rotate CSRF + navigate.
 *
 * Driven through admin/categories/{id}/activate (a representative redirect-with-
 * flash action) so no controller changes are needed for the feature to work.
 */
final class AjaxFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(['category.view', 'category.update']);

        Services::injectMock('categoryRepository', new class {
            public function findById(int $id): ?array { return $id === 2 ? ['id' => 2, 'status' => 'inactive'] : null; }
            public function updateStatus(int $id, string $status, ?int $actorId = null): bool { return true; }
        });
    }

    protected function tearDown(): void
    {
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

    /** A plain POST is untouched — it still redirects (back-compat / JS-disabled path). */
    public function testNonAjaxPostStillRedirects(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/categories/2/activate', $data);

        $result->assertRedirect();
        $this->assertStringContainsString('admin/categories', $result->getRedirectUrl());
    }

    /** The same POST over AJAX comes back as a 200 JSON envelope, not a 302. */
    public function testAjaxPostReturnsJsonEnvelope(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('admin/categories/2/activate', $data);

        $result->assertStatus(200);

        // NOTE: read the body off the response itself, not $result->getBody() —
        // TestResponse::getBody() pipes the body through a DOMParser which wraps
        // fragments in <html><body><p>…</p>. The wire body is the clean JSON below.
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertIsArray($json, 'AJAX redirect should be converted to a JSON body.');
        $this->assertTrue($json['ok']);
        $this->assertSame('success', $json['type']);
        $this->assertStringContainsString('admin/categories', $json['redirect']);
        $this->assertArrayHasKey('csrf', $json);
        $this->assertArrayHasKey('csrf_name', $json);
    }
}
