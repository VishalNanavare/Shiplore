<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * P8 — admin AI-suggest endpoint returns JSON suggestions; RBAC-guarded.
 * Requests are sent as AJAX (as the product-form JS does), so the dev Debug
 * Toolbar doesn't wrap the JSON body.
 */
final class ProductAiTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.test');
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

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}
            public function loadAssignments(int $u): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    private function postSess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 1, 'user_name' => 'A', 'principal_type' => 'platform'];
    }

    /** @param array<string,mixed> $data */
    private function ajaxPost(array $data)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession($this->postSess())
            ->post('admin/products/ai-suggest', $data);
    }

    /** Decode the JSON body, tolerating the dev Debug Toolbar HTML wrapper. */
    private function decode($r): array
    {
        $body = (string) $r->getBody();
        if (str_contains($body, '<') && str_contains($body, '{')) {
            $body = substr($body, (int) strpos($body, '{'), (int) strrpos($body, '}') - (int) strpos($body, '{') + 1);
        }

        return json_decode($body, true) ?: [];
    }

    public function testSeoSuggestReturnsJson(): void
    {
        $this->grant(['product.create']);
        $r = $this->ajaxPost([csrf_token() => csrf_hash(), 'kind' => 'seo', 'title' => 'Running Sneakers', 'category' => 'Footwear', 'brand' => 'Sole Mate']);
        $r->assertStatus(200);
        $json = $this->decode($r);
        $this->assertTrue($json['ok']);
        $this->assertSame('Running Sneakers by Sole Mate | Buy Online', $json['data']['meta_title']);
    }

    public function testTagsSuggestReturnsList(): void
    {
        $this->grant(['product.update']);
        $r = $this->ajaxPost([csrf_token() => csrf_hash(), 'kind' => 'tags', 'title' => 'Yoga Mat', 'category' => 'Fitness']);
        $json = $this->decode($r);
        $this->assertContains('yoga', $json['data']['tags']);
        $this->assertContains('fitness', $json['data']['tags']);
    }

    public function testDeniedWithoutProductPermission(): void
    {
        $this->grant(['order.view']);
        $r = $this->ajaxPost([csrf_token() => csrf_hash(), 'kind' => 'seo', 'title' => 'X']);
        $r->assertStatus(403);
    }
}
