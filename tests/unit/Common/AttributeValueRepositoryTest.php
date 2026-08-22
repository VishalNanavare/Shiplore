<?php

declare(strict_types=1);

use App\Models\AttributeValueRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

/**
 * Phase A1.3 — attribute-value management. Before this, no admin screen managed
 * attribute_values at all; the only code touching that table was a read-only lookup
 * used by the variant builder and (as of A1.1) the specs tab. A value could only ever
 * get into the system by direct SQL.
 */
final class AttributeValueRepositoryTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttributesTable();
        $this->ensureAttributeValuesTable();
        $this->ensureProductsTable();
        $this->ensureProductVariantsTable();
        $this->ensureProductAttributeValuesTable();
        $this->ensureVariantAttributeValuesTable();

        $db = Database::connect();
        $db->table('attributes')->where('id', 1001)->delete();
        $db->table('attributes')->insert([
            'id' => 1001, 'code' => 'color', 'name' => 'Color', 'type' => 'select',
            'is_variant_defining' => 0, 'status' => 'active',
        ]);
        $db->table('attribute_values')->where('attribute_id', 1001)->delete();
    }

    public function testListForAttributeReturnsOnlyThatAttributesValues(): void
    {
        $db = Database::connect();
        $db->table('attributes')->where('id', 1002)->delete();
        $db->table('attributes')->insert([
            'id' => 1002, 'code' => 'size', 'name' => 'Size', 'type' => 'select',
            'is_variant_defining' => 0, 'status' => 'active',
        ]);
        $db->table('attribute_values')->where('attribute_id', 1002)->delete();
        $db->table('attribute_values')->insertBatch([
            ['id' => 2001, 'attribute_id' => 1001, 'value' => 'Red', 'sort_order' => 1],
            ['id' => 2002, 'attribute_id' => 1001, 'value' => 'Blue', 'sort_order' => 0],
            ['id' => 2003, 'attribute_id' => 1002, 'value' => 'M', 'sort_order' => 0],
        ]);

        $repo = new AttributeValueRepository();
        $rows = $repo->listForAttribute(1001);

        $this->assertCount(2, $rows);
        // ordered by sort_order
        $this->assertSame('Blue', $rows[0]['value']);
        $this->assertSame('Red', $rows[1]['value']);
    }

    public function testCreateThenFindById(): void
    {
        $repo = new AttributeValueRepository();
        $id   = $repo->create(['attribute_id' => 1001, 'value' => 'Green', 'sort_order' => 3], 7);

        $row = $repo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('Green', $row['value']);
        $this->assertSame(1001, (int) $row['attribute_id']);
    }

    public function testUpdateChangesValue(): void
    {
        $repo = new AttributeValueRepository();
        $id   = $repo->create(['attribute_id' => 1001, 'value' => 'Purpl', 'sort_order' => 0], 7);

        $this->assertTrue($repo->update($id, ['value' => 'Purple'], 7));
        $this->assertSame('Purple', $repo->findById($id)['value']);
    }

    public function testInUseByEmptyWhenUnused(): void
    {
        $repo = new AttributeValueRepository();
        $id   = $repo->create(['attribute_id' => 1001, 'value' => 'Unused Color'], 7);

        $this->assertSame([], $repo->inUseBy($id));
    }

    public function testInUseByViaProductSpecOnPublishedProduct(): void
    {
        $db  = Database::connect();
        $repo = new AttributeValueRepository();
        $id  = $repo->create(['attribute_id' => 1001, 'value' => 'Spec Color'], 7);

        // is_online_enabled=0 keeps this out of unrelated storefront-visibility queries
        // (StoreCatalogRepository's computeCount() has no category/vendor scope at all)
        // while still satisfying this repository's own status='published' check.
        $db->table('products')->insert(['id' => 3001, 'vendor_id' => 1, 'title' => 'Spec Product', 'status' => 'published', 'is_online_enabled' => 0]);
        $db->table('product_attribute_values')->insert(['product_id' => 3001, 'attribute_id' => 1001, 'attribute_value_id' => $id]);

        $this->assertSame(['Spec Product'], $repo->inUseBy($id));
    }

    public function testInUseByViaVariantOnPublishedProduct(): void
    {
        $db  = Database::connect();
        $repo = new AttributeValueRepository();
        $id  = $repo->create(['attribute_id' => 1001, 'value' => 'Variant Color'], 7);

        $db->table('products')->insert(['id' => 3002, 'vendor_id' => 1, 'title' => 'Variant Product', 'status' => 'published', 'is_online_enabled' => 0]);
        $db->table('product_variants')->insert(['id' => 4001, 'product_id' => 3002, 'vendor_id' => 1, 'sku' => 'VP-1', 'mrp' => '10', 'base_price' => '9', 'status' => 'active']);
        $db->table('variant_attribute_values')->insert(['variant_id' => 4001, 'attribute_id' => 1001, 'attribute_value_id' => $id]);

        $this->assertSame(['Variant Product'], $repo->inUseBy($id));
    }

    public function testDeleteBlockedIsRepositorysCallersResponsibilityNotEnforcedHere(): void
    {
        // The repository exposes delete() unconditionally — the controller (not the
        // repository) is responsible for calling inUseBy() first and refusing, same
        // separation AttributeController/AttributeRepository already use. This test
        // documents that delete() itself has no built-in guard.
        $repo = new AttributeValueRepository();
        $id   = $repo->create(['attribute_id' => 1001, 'value' => 'To Delete'], 7);

        $this->assertTrue($repo->delete($id, 7));
        $this->assertNull($repo->findById($id));
    }

    public function testUpdateStatusTogglesActiveInactive(): void
    {
        $repo = new AttributeValueRepository();
        $id   = $repo->create(['attribute_id' => 1001, 'value' => 'Togglable'], 7);

        $this->assertTrue($repo->updateStatus($id, 'inactive', 7));
        $this->assertSame('inactive', $repo->findById($id)['status']);
    }
}
