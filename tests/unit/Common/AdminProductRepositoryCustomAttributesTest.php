<?php

declare(strict_types=1);

use App\Models\AdminProductRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A1.1 — makes attributes.type functional on the product specs tab. Before
 * this, every non-variant attribute round-tripped through value_text regardless of
 * type, so a "select"-type attribute (e.g. Color) had no controlled vocabulary in
 * practice — the same free-text problem the variant builder never had.
 *
 * saveCustomAttributes() is private (called from deep inside create()/update(),
 * which also touch several unrelated satellite tables — content, SEO, tags, shop
 * assignment). Invoked here via reflection so this test exercises exactly the
 * behavior in question without needing to fixture the whole product lifecycle.
 */
final class AdminProductRepositoryCustomAttributesTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();
        $this->ensureProductAttributeValuesTable();
    }

    private function save(int $productId, array $map, ?int $actorId = null): void
    {
        $repo   = new AdminProductRepository();
        $method = new ReflectionMethod(AdminProductRepository::class, 'saveCustomAttributes');
        $method->setAccessible(true);
        $method->invoke($repo, $productId, $map, $actorId);
    }

    public function testTextTypeAttributeSavesToValueText(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 801, 'code' => 'material', 'name' => 'Material', 'type' => 'text',
            'is_variant_defining' => 0, 'status' => 'active',
        ]);

        $this->save(901, ['801' => 'Cotton'], 5);

        $rows = $db->table('product_attribute_values')->where('product_id', 901)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('Cotton', $rows[0]['value_text']);
        $this->assertNull($rows[0]['attribute_value_id']);
    }

    public function testSelectTypeAttributeSavesToAttributeValueIdNotValueText(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 802, 'code' => 'color', 'name' => 'Color', 'type' => 'select',
            'is_variant_defining' => 0, 'status' => 'active',
        ]);
        $db->table('attribute_values')->insert([
            'id' => 802001, 'attribute_id' => 802, 'value' => 'White',
        ]);

        // The form submits the chosen attribute_value_id as the raw string value —
        // same POST field name (cattr[id]) as text-type attributes, no separate
        // field needed; the type lookup below is what decides how to interpret it.
        $this->save(902, ['802' => '802001'], 5);

        $rows = $db->table('product_attribute_values')->where('product_id', 902)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['value_text']);
        $this->assertSame(802001, (int) $rows[0]['attribute_value_id']);
    }

    public function testResavingReplacesRatherThanAccumulates(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 803, 'code' => 'warranty', 'name' => 'Warranty', 'type' => 'text',
            'is_variant_defining' => 0, 'status' => 'active',
        ]);

        $this->save(903, ['803' => '1 year'], 5);
        $this->save(903, ['803' => '2 years'], 5);

        $rows = $db->table('product_attribute_values')->where('product_id', 903)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('2 years', $rows[0]['value_text']);
    }

    public function testCustomAttributesReadsBackBothShapesWithDisplayText(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insertBatch([
            ['id' => 804, 'code' => 'material2', 'name' => 'Material', 'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active'],
            ['id' => 805, 'code' => 'color2', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 0, 'status' => 'active'],
        ]);
        $db->table('attribute_values')->insert([
            'id' => 805001, 'attribute_id' => 805, 'value' => 'Ivory',
        ]);
        $db->table('product_attribute_values')->insertBatch([
            ['product_id' => 904, 'attribute_id' => 804, 'value_text' => 'Cotton', 'attribute_value_id' => null],
            ['product_id' => 904, 'attribute_id' => 805, 'attribute_value_id' => 805001, 'value_text' => null],
        ]);

        $repo = new AdminProductRepository();
        $out  = $repo->customAttributes(904);

        $this->assertSame('Cotton', $out[804]['value_text']);
        $this->assertNull($out[804]['attribute_value_id']);

        $this->assertNull($out[805]['value_text']);
        $this->assertSame(805001, $out[805]['attribute_value_id']);
        $this->assertSame('Ivory', $out[805]['attribute_value_text']);
    }
}
