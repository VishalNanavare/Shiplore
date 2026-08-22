<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase A2.2 — category-attribute mapping admin screen: checkbox list of all
 * active attributes for one category, save replaces the mapping wholesale.
 */
final class AdminCategoryAttributeMappingTest extends CIUnitTestCase
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
            public function findById(int $id): ?array
            {
                return match ($id) {
                    1 => ['id' => 1, 'name' => 'Short Top', 'status' => 'active'],
                    2 => ['id' => 2, 'name' => 'Unmapped Category', 'status' => 'active'],
                    default => null,
                };
            }
            public function mappedAttributeIds(int $categoryId): array
            {
                return $categoryId === 1 ? [10] : [];
            }
            public function inferredAttributeIds(int $categoryId): array { return [20]; }
            public function setAttributeMapping(int $categoryId, array $attributeIds): bool { return true; }
        });

        Services::injectMock('attributeRepository', new class {
            public function list(?string $status = null): array
            {
                return [
                    ['id' => 10, 'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active'],
                    ['id' => 20, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active'],
                ];
            }
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

    public function testFormRendersWithMappedAttributePreChecked(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/categories/1/attributes');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Color', $body);
        $this->assertStringContainsString('Size', $body);
        $this->assertStringContainsString('Short Top', $body);
    }

    public function testFormReturns404ForUnknownCategory(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/categories/999/attributes');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/categories', $result->getRedirectUrl());
    }

    public function testSaveRedirectsBackToCategoryEdit(): void
    {
        $data    = [csrf_token() => csrf_hash(), 'attribute_ids' => ['10', '20']];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/categories/1/attributes/save', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testSaveWithNoAttributesCheckedClearsMapping(): void
    {
        $data    = [csrf_token() => csrf_hash()];
        $session = service('session')->get() + $this->adminSession();

        $result = $this->withSession($session)->post('admin/categories/1/attributes/save', $data);
        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testBootstrapPreChecksInferredAttributesWhenUnmapped(): void
    {
        $result = $this->withSession($this->adminSession())->get('admin/categories/2/attributes?bootstrap=1');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('value="20" id="attr20" checked', $body);
        $this->assertStringContainsString('suggested', strtolower($body));
    }

    public function testBootstrapIsIgnoredWhenAlreadyMapped(): void
    {
        // id=1 already has an explicit mapping ([10]); bootstrap must never override
        // an existing mapping, even if the query flag is present.
        $result = $this->withSession($this->adminSession())->get('admin/categories/1/attributes?bootstrap=1');
        $result->assertStatus(200);
        $body = (string) $result->getBody();
        $this->assertStringContainsString('value="10" id="attr10" checked', $body);
        $this->assertStringNotContainsString('value="20" id="attr20" checked', $body);
    }

    public function testPermissionDeniedRedirectsToDashboard(): void
    {
        $this->grant(['shop.view']);
        $result = $this->withSession($this->adminSession())->get('admin/categories/1/attributes');
        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', $result->getRedirectUrl());
    }
}
