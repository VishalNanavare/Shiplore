<?php

declare(strict_types=1);

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * A panel path asked for on the wrong panel host redirects instead of 404ing.
 *
 * The operator hit this signed in as an admin: typing
 * manufacturer.<domain>/admin/dashboard returned a bare 404, when the path plainly
 * belongs to the admin host. Every panel group is subdomain-pinned, so on the wrong host
 * the route is never registered and Router::handle() throws before any filter runs —
 * set404Override is the only hook on that path, which is why this is an override and not
 * a filter.
 *
 * The target is derived from the PATH, never the session: which host serves
 * /admin/dashboard is a fact about the routing table, identical for every visitor.
 * Consulting the session would start one for every crawler hitting a stale URL, make the
 * response uncacheable, and turn the 404 handler into an oracle for the viewer's
 * principal type. Identity is still enforced one hop later, on the correct host, by
 * WebAuthFilter — where it belongs.
 */
final class PanelRedirectTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private function onHost(string $host): void
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::resetSingle('siteurifactory');
        Services::resetSingle('uri');
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    /** The reported case, exactly. */
    public function testAnAdminPathOnTheManufacturerHostRedirectsToAdmin(): void
    {
        $this->onHost('manufacturer.shiplore.test');

        $r = $this->get('admin/dashboard');

        $r->assertRedirect();
        $this->assertStringContainsString('admin.shiplore.test/admin/dashboard', (string) $r->getRedirectUrl());
    }

    /** …and it works the other way too, so this is not an admin-only courtesy. */
    public function testAManufacturerPathOnTheAdminHostRedirectsToManufacturer(): void
    {
        $this->onHost('admin.shiplore.test');

        $r = $this->get('manufacturer/products');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer.shiplore.test/manufacturer/products', (string) $r->getRedirectUrl());
    }

    /**
     * A path that is simply missing ON ITS OWN HOST must still 404.
     *
     * This is the loop guard. Without it, admin.<domain>/admin/typo would redirect to
     * admin.<domain>/admin/typo forever.
     */
    public function testAGenuinelyMissingPathOnTheRightHostStill404s(): void
    {
        $this->onHost('admin.shiplore.test');

        $this->expectException(PageNotFoundException::class);
        $this->get('admin/no-such-page');
    }

    /**
     * The second host of a dual-subdomain group is an ACCEPTED host, not a wrong one.
     *
     * vendor. and shop. both serve the vendor group — shop. is the unit-staff login. A
     * 404 there is a real 404, and bouncing a shop user onto the owner login would be a
     * worse answer than the 404 they asked for.
     */
    public function testTheSecondHostOfAGroupIsNotTreatedAsWrong(): void
    {
        $this->onHost('shop.shiplore.test');

        $this->expectException(PageNotFoundException::class);
        $this->get('vendor/no-such-page');
    }

    /**
     * Non-GET is never redirected.
     *
     * redirect()->to() answers a POST with 303, which downgrades the method and drops
     * the body — the user would silently "succeed" at nothing. 307 would carry it, but
     * replaying a body cross-origin under a domain-wide session cookie is not a decision
     * a 404 handler should make.
     */
    public function testAPostIsNotRedirected(): void
    {
        $this->onHost('manufacturer.shiplore.test');

        $this->expectException(PageNotFoundException::class);
        $this->post('admin/dashboard');
    }

    /**
     * Matching is on the first SEGMENT, not a string prefix.
     *
     * `manufacturer-register` is a real top-level route served on the apex. A
     * str_starts_with('manufacturer') test would capture it and bounce it off the very
     * host that serves it.
     */
    public function testASimilarlyNamedPathIsNotBounced(): void
    {
        $this->onHost('shiplore.test');

        // NOT 'manufacturer-register' itself — that route RESOLVES on the apex, so the
        // override never runs and the assertion could not fail either way. A mutation run
        // proved exactly that: swapping segment matching for str_starts_with left this
        // test green. The distinction only bites on a path that both starts with a panel
        // name AND fails to resolve, which is when the override actually fires.
        $this->expectException(PageNotFoundException::class);
        $this->get('manufacturer-register/nope');
    }

    /**
     * api/v1 and the storefront are NOT subdomain-pinned — they answer everywhere — so a
     * miss there is a real 404 and must never be redirected.
     */
    public function testUnpinnedSurfacesAreNeverRedirected(): void
    {
        $this->onHost('manufacturer.shiplore.test');

        $this->expectException(PageNotFoundException::class);
        $this->get('api/v1/no-such-endpoint');
    }

    /** An unknown first segment is just a 404, not a guess. */
    public function testAnUnknownPathIsNotRedirected(): void
    {
        $this->onHost('manufacturer.shiplore.test');

        $this->expectException(PageNotFoundException::class);
        $this->get('totally-unknown-thing');
    }
}
