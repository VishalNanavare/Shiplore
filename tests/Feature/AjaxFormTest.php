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

    // ------------------------------------------------------- cross-origin guard

    /**
     * A form whose action leaves this origin must be handed back to the browser.
     *
     * The interceptor replays every POST through fetch() with an X-Requested-With header
     * (not CORS-safelisted, so a preflight nothing answers) and credentials:'same-origin'
     * (which drops the .shiplore.test cookie cross-origin). Both are fatal, and both fail
     * SILENTLY as far as the server is concerned — nothing reaches PHP, so there is no
     * 404, no log line, no audit row. The impersonation banner's "Return to Admin" button
     * sat broken exactly this way once panel_url() made its action cross-origin.
     *
     * Asserted on the shipped file. There is no JS test runner in this project, so this
     * is a source assertion — it pins that the guard is WIRED INTO isExcluded(), not
     * merely defined, which is the way this could silently regress.
     */
    public function testAjaxFormsLeavesCrossOriginFormsToTheBrowser(): void
    {
        $js = (string) file_get_contents(FCPATH . 'assets/js/ajax-forms.js');

        // Strip comments first — the block above isCrossOrigin() discusses the very
        // identifiers being searched for, and has already fooled assertions in this repo.
        $code = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $js);

        $this->assertMatchesRegularExpression(
            '/function isCrossOrigin\s*\(\s*form\s*\)/',
            $code,
            'the cross-origin guard must exist',
        );
        $this->assertMatchesRegularExpression(
            '/new URL\(\s*action\s*,\s*window\.location\.href\s*\)\.origin\s*!==\s*window\.location\.origin/',
            $code,
            'it must compare ORIGIN — same-site is not enough, fetch() refuses cross-origin',
        );
        $this->assertMatchesRegularExpression(
            '/function isExcluded[\s\S]{0,600}?isCrossOrigin\(form\)\s*\)\s*\{\s*return true;/',
            $code,
            'defining the guard is not enough — isExcluded() must actually call it',
        );
    }

    /** The mirrored copy under assets/ must not drift from the one the browser loads. */
    public function testAjaxFormsSourceAndPublicCopiesAreIdentical(): void
    {
        $this->assertSame(
            md5_file(ROOTPATH . 'assets/js/ajax-forms.js'),
            md5_file(FCPATH . 'assets/js/ajax-forms.js'),
            'assets/js/ajax-forms.js and public/assets/js/ajax-forms.js have diverged',
        );
    }
}
