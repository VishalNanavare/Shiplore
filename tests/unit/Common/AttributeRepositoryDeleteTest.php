<?php

declare(strict_types=1);

use App\Models\AttributeRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once __DIR__ . '/../../_support/MinimalSchema.php';

final class AttributeRepositoryDeleteTest extends CIUnitTestCase
{
    use MinimalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAttributesTable();
    }

    public function testDeleteSetsDeletedAtAndRemovesFromFindById(): void
    {
        $db = Database::connect();
        $db->table('attributes')->insert([
            'id' => 511, 'code' => 'to_delete', 'name' => 'To Delete',
            'type' => 'text', 'is_variant_defining' => 0, 'status' => 'active',
        ]);

        $repo = new AttributeRepository();
        $this->assertTrue($repo->delete(511, 9));

        $this->assertNull($repo->findById(511));

        $raw = $db->table('attributes')->where('id', 511)->get()->getRowArray();
        $this->assertNotNull($raw['deleted_at']);
        $this->assertSame(9, (int) $raw['updated_by']);
    }
}
