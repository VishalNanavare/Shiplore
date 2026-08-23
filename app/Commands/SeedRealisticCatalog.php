<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\CategoryRepository;
use App\Models\CommissionRuleRepository;
use App\Models\ProductApprovalRepository;
use App\Models\ProductVariantRepository;
use App\Models\RegistrationRepository;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * SeedRealisticCatalog — Track C-ii: a brand-new, fully realistic clothing +
 * footwear catalog, entirely through the same repositories the real admin/vendor
 * panels use (RegistrationRepository::createVendorWithShop(), AdminProductRepository::
 * createWithVariant(), ProductVariantRepository::generate(), ProductApprovalRepository's
 * submit/approve/publish chain) rather than hand-rolled INSERTs, so every row this
 * produces is exactly as valid as one created through the UI.
 *
 * MUST be run AFTER database/sql/resets/{00,01,02}*.sql have been imported by hand —
 * this command does not wipe anything itself, it only creates. Running it against a
 * database that still has old categories/attributes/products will just add alongside
 * them, which defeats the point of a fresh catalog.
 *
 * php spark catalog:seed-realistic
 * php spark catalog:seed-realistic --shops 5      (fewer shops, for a quick local check)
 * php spark catalog:seed-realistic --dry-run       (print the plan, write nothing)
 *
 * Location anchor: Adarsh Nagar, Jogeshwari West, Mumbai (the operator's own Plus Code,
 * 4RVM+X2R, Mumbai 400102), resolved to 19.1421,72.8346 via OpenStreetMap Nominatim —
 * every shop is placed within ~2.5km of this point with a small deterministic offset,
 * matching seed_mumbai_demo.php's own convention of real-landmark lat/lng + jitter.
 */
final class SeedRealisticCatalog extends BaseCommand
{
    protected $group       = 'Ops';
    protected $name        = 'catalog:seed-realistic';
    protected $description = 'Seed a brand-new realistic clothing/footwear catalog (Track C-ii).';
    protected $usage       = 'catalog:seed-realistic [--shops N] [--products-per-shop N] [--dry-run]';
    protected $options     = [
        '--shops'             => 'Number of shops to create (default 10, matches the 10 leaf categories 1:1; max 10).',
        '--products-per-shop' => 'Products per shop (default 155; minimum requirement was 150).',
        '--dry-run'           => 'Print the plan (shops, categories, product/variant counts) without writing anything.',
    ];

    /** users.id=1 'Super Admin' — the seeded platform account (11_seed.sql:398), explicitly
     *  preserved by Track C's wipe. Used as the real actor for every created row's audit
     *  trail rather than 0/null, since submit()/approve()/publish() declare a non-nullable
     *  int $actorId. */
    private const SEED_ACTOR_ID = 1;

    private const ANCHOR_LAT = 19.1421;
    private const ANCHOR_LNG = 72.8346;

    /** Small deterministic offsets (km-ish in degrees) around the anchor — a compass rose, not random, so re-runs are reproducible. */
    private const SHOP_OFFSETS = [
        [0.006, 0.004], [-0.008, 0.010], [0.014, -0.006], [-0.005, -0.013], [0.020, 0.002],
        [-0.018, 0.008], [0.002, 0.021], [-0.011, -0.019], [0.017, 0.015], [-0.022, -0.003],
    ];

    private const COLORS = [
        'Black', 'White', 'Navy Blue', 'Maroon', 'Mustard Yellow',
        'Olive Green', 'Grey Melange', 'Pastel Pink', 'Royal Blue', 'Beige',
    ];
    private const CLOTHING_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
    private const SHOE_SIZES     = ['UK 5', 'UK 6', 'UK 7', 'UK 8', 'UK 9', 'UK 10'];

    /** unit_id=1 'Piece', tax_class_id=3 'GST_12' — standard, real seeded rows (11_seed.sql). */
    private const UNIT_ID     = 1;
    private const TAX_CLASS_ID = 3;

    /**
     * Category tree, each leaf mapped 1:1 to a shop below. Fields:
     * name, parent (null = top-level), sizeAttr ('clothing'|'shoe'), commissionRate,
     * priceRange [min,max] for base_price, mrpMultiplier range, garment word banks.
     */
    private const CATEGORIES = [
        'womens_clothing' => ['name' => "Women's Clothing", 'parent' => null],
        'mens_clothing'   => ['name' => "Men's Clothing", 'parent' => null],
        'footwear'        => ['name' => 'Footwear', 'parent' => null],

        'tops'    => ['name' => 'Tops & Tees', 'parent' => 'womens_clothing', 'sizeAttr' => 'clothing', 'rate' => 8.00, 'price' => [399, 1299], 'mrpMult' => [1.3, 1.7]],
        'kurtis'  => ['name' => 'Kurtis & Ethnic Wear', 'parent' => 'womens_clothing', 'sizeAttr' => 'clothing', 'rate' => 9.50, 'price' => [599, 1999], 'mrpMult' => [1.3, 1.6]],
        'dresses' => ['name' => 'Dresses', 'parent' => 'womens_clothing', 'sizeAttr' => 'clothing', 'rate' => 10.00, 'price' => [799, 2499], 'mrpMult' => [1.35, 1.75]],
        'wjeans'  => ['name' => 'Jeans & Trousers', 'parent' => 'womens_clothing', 'sizeAttr' => 'clothing', 'rate' => 7.50, 'price' => [999, 2999], 'mrpMult' => [1.25, 1.6]],
        'shirts'  => ['name' => 'Shirts', 'parent' => 'mens_clothing', 'sizeAttr' => 'clothing', 'rate' => 8.00, 'price' => [599, 1999], 'mrpMult' => [1.3, 1.65]],
        'tshirts' => ['name' => 'T-Shirts', 'parent' => 'mens_clothing', 'sizeAttr' => 'clothing', 'rate' => 7.00, 'price' => [399, 1299], 'mrpMult' => [1.25, 1.6]],
        'mtrouser'=> ['name' => 'Trousers & Jeans', 'parent' => 'mens_clothing', 'sizeAttr' => 'clothing', 'rate' => 7.50, 'price' => [899, 2799], 'mrpMult' => [1.25, 1.6]],
        'casual'  => ['name' => 'Casual Shoes', 'parent' => 'footwear', 'sizeAttr' => 'shoe', 'rate' => 8.50, 'price' => [899, 3499], 'mrpMult' => [1.3, 1.7]],
        'sandals' => ['name' => 'Sandals & Flip-Flops', 'parent' => 'footwear', 'sizeAttr' => 'shoe', 'rate' => 6.00, 'price' => [349, 1499], 'mrpMult' => [1.25, 1.55]],
        'kids'    => ['name' => 'Kids Wear', 'parent' => null, 'sizeAttr' => 'clothing', 'rate' => 6.50, 'price' => [349, 999], 'mrpMult' => [1.25, 1.55]],
    ];

    /** One shop per leaf category, in the order shops are created. */
    private const SHOPS = [
        ['name' => 'Trendy Threads Boutique', 'category' => 'tops'],
        ['name' => 'EthniQ Studio', 'category' => 'kurtis'],
        ['name' => 'Dress Diaries', 'category' => 'dresses'],
        ['name' => 'Blue Denim Co.', 'category' => 'wjeans'],
        ['name' => 'The Shirt Story', 'category' => 'shirts'],
        ['name' => 'Urban Tee Collective', 'category' => 'tshirts'],
        ['name' => 'Formal Fit Trousers', 'category' => 'mtrouser'],
        ['name' => 'StepUp Footwear', 'category' => 'casual'],
        ['name' => 'Flip & Stride', 'category' => 'sandals'],
        ['name' => 'Little Feet Kidswear', 'category' => 'kids'],
    ];

    /** Word banks for deterministic-but-varied product naming per garment family. */
    private const FABRICS = ['Cotton', 'Rayon', 'Georgette', 'Linen', 'Polyester', 'Viscose', 'Crepe', 'Denim', 'Chambray', 'Poplin'];
    private const STYLES_TOP = ['Printed', 'Solid', 'Striped', 'Floral', 'Checked', 'Puff-Sleeve', 'Tie-Up', 'Round Neck', 'V-Neck', 'Wrap'];
    private const STYLES_ETHNIC = ['Embroidered', 'Printed', 'Solid', 'A-Line', 'Straight-Cut', 'Anarkali', 'Chikankari', 'Block-Print'];
    private const STYLES_DRESS = ['Wrap', 'A-Line', 'Bodycon', 'Fit & Flare', 'Shirt-Style', 'Maxi', 'Floral-Print', 'Solid'];
    private const FITS = ['Slim-Fit', 'Regular-Fit', 'Relaxed-Fit', 'Skinny-Fit', 'Straight-Fit', 'Bootcut'];
    private const SHOE_STYLES = ['Lace-Up Sneaker', 'Slip-On Sneaker', 'Running Shoe', 'Loafer', 'Canvas Shoe', 'Chukka Boot'];
    private const SHOE_MATERIALS = ['Canvas', 'Suede', 'Mesh', 'Synthetic Leather', 'Knit'];
    private const SANDAL_STYLES = ['Flip-Flop', 'Sports Sandal', 'Slider', 'Ankle-Strap Sandal', 'Kolhapuri Sandal'];
    private const KIDS_STYLES = ['T-Shirt', 'Shorts Set', 'Dungaree', 'Frock', 'Joggers'];
    private const KIDS_PRINTS = ['Dino-Print', 'Floral', 'Striped', 'Cartoon-Print', 'Solid-Color'];

    private ?CategoryRepository $categories = null;
    private ?ProductVariantRepository $variants = null;
    private ?RegistrationRepository $registration = null;
    private ?ProductApprovalRepository $approval = null;
    private ?CommissionRuleRepository $commissionRules = null;

    public function run(array $params): int
    {
        $this->categories      = service('categoryRepository');
        $this->variants        = service('productVariantRepository');
        $this->registration    = service('registrationRepository');
        $this->approval        = service('productApprovalRepository');
        $this->commissionRules = service('commissionRuleRepository');

        $shopCount   = min(10, max(1, (int) ($params['shops'] ?? CLI::getOption('shops') ?? 10)));
        $perShop     = max(1, (int) ($params['products-per-shop'] ?? CLI::getOption('products-per-shop') ?? 155));
        $dryRun      = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        CLI::write('catalog:seed-realistic — ' . $shopCount . ' shop(s) x ' . $perShop . ' product(s) near Adarsh Nagar, Jogeshwari West, Mumbai.', 'yellow');
        if ($dryRun) {
            CLI::write('Dry run: no rows will be written.', 'yellow');
            foreach (array_slice(self::SHOPS, 0, $shopCount) as $s) {
                $cat = self::CATEGORIES[$s['category']];
                CLI::write('  - ' . $s['name'] . ' -> ' . $cat['name'] . ' (' . $perShop . ' products)');
            }

            return 0;
        }

        $planId = $this->activeCommissionPlanId();
        if ($planId === null) {
            CLI::error('No active commission_plans row found — cannot create commission_rules. Aborting.');

            return 1;
        }

        $categoryIds  = $this->seedCategories();
        [$colorId, $clothingSizeId, $shoeSizeId] = $this->seedAttributes();
        $this->mapCategoryAttributes($categoryIds, $colorId, $clothingSizeId, $shoeSizeId);
        $this->seedCommissionRules($categoryIds, $planId);

        $totalProducts = 0;
        $totalVariants = 0;

        foreach (array_slice(self::SHOPS, 0, $shopCount) as $shopIndex => $shopDef) {
            $catKey  = $shopDef['category'];
            $catMeta = self::CATEGORIES[$catKey];
            $catId   = $categoryIds[$catKey];
            $sizeId  = $catMeta['sizeAttr'] === 'shoe' ? $shoeSizeId : $clothingSizeId;
            $sizeValues = $catMeta['sizeAttr'] === 'shoe' ? self::SHOE_SIZES : self::CLOTHING_SIZES;

            [$vendorId, $shopId] = $this->seedShop($shopIndex, $shopDef['name'], $catMeta['name']);
            if ($vendorId === null) {
                CLI::error('  Could not create vendor/shop for "' . $shopDef['name'] . '" — skipping.');

                continue;
            }

            CLI::write($shopDef['name'] . ' (' . $catMeta['name'] . ') — vendor #' . $vendorId . ', shop #' . $shopId, 'green');

            $created = $this->seedProductsForShop(
                $vendorId, $shopId, $catKey, $catId, $catMeta,
                $colorId, $sizeId, $sizeValues, $perShop,
            );
            $totalProducts += $created['products'];
            $totalVariants += $created['variants'];
            CLI::write('  ' . $created['products'] . ' products, ' . $created['variants'] . ' variants.');
        }

        CLI::write('Done. ' . $totalProducts . ' products, ' . $totalVariants . ' variants across ' . $shopCount . ' shop(s).', 'green');
        CLI::write('Next: php spark facets:refresh --rebuild-global', 'yellow');

        return 0;
    }

    // ------------------------------------------------------------------ categories

    /** @return array<string,int> category key => id */
    private function seedCategories(): array
    {
        $ids = [];
        // parents first (no dependency), then leaves (need parent id).
        foreach (self::CATEGORIES as $key => $meta) {
            if ($meta['parent'] !== null) {
                continue;
            }
            $ids[$key] = (int) $this->categories->create(['name' => $meta['name'], 'parent_id' => null, 'sort_order' => 0], self::SEED_ACTOR_ID);
        }
        foreach (self::CATEGORIES as $key => $meta) {
            if ($meta['parent'] === null) {
                continue;
            }
            $ids[$key] = (int) $this->categories->create(['name' => $meta['name'], 'parent_id' => $ids[$meta['parent']], 'sort_order' => 0], self::SEED_ACTOR_ID);
        }

        return $ids;
    }

    // ------------------------------------------------------------------ attributes

    /** @return array{0:int,1:int,2:int} colorAttrId, clothingSizeAttrId, shoeSizeAttrId */
    private function seedAttributes(): array
    {
        $master = service('masterRepository');
        $colorId = $master->create('attributes', 'attributes', [
            'code' => 'color', 'name' => 'Color', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active',
        ], self::SEED_ACTOR_ID);
        $sizeId = $master->create('attributes', 'attributes', [
            'code' => 'size', 'name' => 'Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active',
        ], self::SEED_ACTOR_ID);
        $shoeSizeId = $master->create('attributes', 'attributes', [
            'code' => 'shoe_size', 'name' => 'Shoe Size', 'type' => 'select', 'is_variant_defining' => 1, 'status' => 'active',
        ], self::SEED_ACTOR_ID);

        $valueRepo = service('attributeValueRepository');
        foreach (self::COLORS as $i => $c) {
            $valueRepo->create(['attribute_id' => $colorId, 'value' => $c, 'sort_order' => $i], self::SEED_ACTOR_ID);
        }
        foreach (self::CLOTHING_SIZES as $i => $s) {
            $valueRepo->create(['attribute_id' => $sizeId, 'value' => $s, 'sort_order' => $i], self::SEED_ACTOR_ID);
        }
        foreach (self::SHOE_SIZES as $i => $s) {
            $valueRepo->create(['attribute_id' => $shoeSizeId, 'value' => $s, 'sort_order' => $i], self::SEED_ACTOR_ID);
        }

        return [(int) $colorId, (int) $sizeId, (int) $shoeSizeId];
    }

    /** @param array<string,int> $categoryIds */
    private function mapCategoryAttributes(array $categoryIds, int $colorId, int $clothingSizeId, int $shoeSizeId): void
    {
        foreach (self::CATEGORIES as $key => $meta) {
            if (! isset($meta['sizeAttr'])) {
                continue; // top-level parents carry no attributes of their own
            }
            $sizeId = $meta['sizeAttr'] === 'shoe' ? $shoeSizeId : $clothingSizeId;
            $this->categories->setAttributeMapping($categoryIds[$key], [$colorId, $sizeId]);
        }
    }

    // ------------------------------------------------------------------ commission

    private function activeCommissionPlanId(): ?int
    {
        $row = Database::connect()->table('commission_plans')->select('id')
            ->where('status', 'active')->where('deleted_at', null)->orderBy('id', 'ASC')->get()->getRowArray();

        return $row !== null ? (int) $row['id'] : null;
    }

    /** @param array<string,int> $categoryIds */
    private function seedCommissionRules(array $categoryIds, int $planId): void
    {
        foreach (self::CATEGORIES as $key => $meta) {
            if (! isset($meta['rate'])) {
                continue;
            }
            $this->commissionRules->createRule([
                'commission_plan_id' => $planId,
                'category_id'        => $categoryIds[$key],
                'commission_type'    => 'percentage',
                'rate'               => $meta['rate'],
                'priority'           => 0,
            ], self::SEED_ACTOR_ID);
        }
    }

    // ------------------------------------------------------------------ shops

    /** @return array{0:int|null,1:int|null} vendorId, shopId */
    private function seedShop(int $index, string $shopName, string $categoryLabel): array
    {
        [$dLat, $dLng] = self::SHOP_OFFSETS[$index % count(self::SHOP_OFFSETS)];
        $slugBase = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $shopName));
        $suffix   = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

        $vendorId = $this->registration->createVendorWithShop([
            'display_name'     => $shopName,
            'legal_name'       => $shopName . ' Retail Pvt Ltd',
            'email'            => $slugBase . $suffix . '@shiplore-demo.example',
            'mobile'           => '90000' . str_pad((string) (10000 + $index), 5, '0', STR_PAD_LEFT),
            'password'         => bin2hex(random_bytes(8)),
            'business_type_id' => $this->businessTypeId($categoryLabel),
            'gstin'            => '27' . strtoupper($slugBase . $suffix) . '1Z' . $index,
            'shop_name'        => $shopName,
            'address'          => 'Shop ' . ($index + 1) . ', ' . $shopName . ' Complex',
            'area'             => 'Adarsh Nagar, Jogeshwari West',
            'city'             => 'Mumbai',
            'state_code'       => '27',
            'pincode'          => '400102',
            'latitude'         => self::ANCHOR_LAT + $dLat,
            'longitude'        => self::ANCHOR_LNG + $dLng,
            'delivery_radius'  => 8,
        ]);
        if ($vendorId === null) {
            return [null, null];
        }

        $db = Database::connect();
        $db->table('vendors')->where('id', $vendorId)->update(['status' => 'active']);
        $shop = $db->table('shops')->select('id')->where('vendor_id', $vendorId)->get()->getRowArray();
        $db->table('shops')->where('id', $shop['id'])->update(['status' => 'active']);

        return [$vendorId, (int) $shop['id']];
    }

    private function businessTypeId(string $categoryLabel): ?int
    {
        $code = $categoryLabel === 'Footwear' ? 'footwear' : 'fashion';
        $row  = Database::connect()->table('business_types')->select('id')->where('code', $code)->get()->getRowArray();

        return $row !== null ? (int) $row['id'] : null;
    }

    // ------------------------------------------------------------------ products

    /**
     * @param array{sizeAttr:string,price:list<int>,mrpMult:list<float>} $catMeta
     * @param list<string> $sizeValues
     * @return array{products:int,variants:int}
     */
    private function seedProductsForShop(int $vendorId, int $shopId, string $catKey, int $categoryId, array $catMeta, int $colorId, int $sizeId, array $sizeValues, int $count): array
    {
        $colorValueIds = $this->valueIdsInOrder($colorId, self::COLORS);
        $sizeValueIds  = $this->valueIdsInOrder($sizeId, $sizeValues);

        $names = $this->productNames($catKey, $count);
        $products = 0;
        $variants = 0;

        foreach ($names as $i => $title) {
            [$min, $max] = $catMeta['price'];
            $base  = $min + (int) (($max - $min) * (($i * 37) % 100) / 100);
            $mult  = $catMeta['mrpMult'][0] + ($catMeta['mrpMult'][1] - $catMeta['mrpMult'][0]) * ((($i * 17) % 100) / 100);
            $mrp   = (int) round($base * $mult);

            $productId = service('adminProductRepository')->createWithVariant([
                'vendor_id'         => $vendorId,
                'category_id'       => $categoryId,
                'tax_class_id'      => self::TAX_CLASS_ID,
                'unit_id'           => self::UNIT_ID,
                'title'             => $title,
                'description'       => $title . ' from our latest collection — quality fabric, everyday comfort, true-to-size fit.',
                'mrp'               => $mrp,
                'base_price'        => $base,
                // headerColumns() defaults this OFF for admin-created drafts; this
                // seeder simulates a live storefront catalog, so it must opt in
                // explicitly or every seeded product publishes invisible to the
                // customer-facing product query (visibleScope() requires it = 1).
                'is_online_enabled' => 1,
            ], self::SEED_ACTOR_ID);
            if ($productId === null) {
                continue;
            }
            $products++;

            // 3 colors x all sizes for this category (clothing: 6 sizes -> 18
            // variants/product; footwear: 6 shoe sizes -> 18) — deterministic
            // rotation through the palette so every product isn't identically colored.
            $colorsForThis = [
                $colorValueIds[$i % count($colorValueIds)],
                $colorValueIds[($i + 3) % count($colorValueIds)],
                $colorValueIds[($i + 6) % count($colorValueIds)],
            ];
            $created = $this->variants->generate(
                $productId, $vendorId,
                [$colorId => $colorsForThis, $sizeId => $sizeValueIds],
                ['skuPrefix' => 'SKU' . $productId, 'mrp' => $mrp, 'base_price' => $base],
                self::SEED_ACTOR_ID,
            );
            $variants += $created;

            $this->stockAndPublish($productId, $vendorId, $shopId, $i);
        }

        return ['products' => $products, 'variants' => $variants];
    }

    /**
     * Real inventory per variant (on_hand only — available is a DB-generated
     * column, never written directly), then walk the real approval chain to
     * 'published' so the storefront actually shows it. Every 7th variant is
     * deliberately zero-stock so the cascading picker's out-of-stock state is
     * genuinely demonstrable in this seeded data, not just in unit tests.
     */
    private function stockAndPublish(int $productId, int $vendorId, int $shopId, int $productIndex): void
    {
        $db = Database::connect();
        $variantIds = $db->table('product_variants')->select('id')
            ->where('product_id', $productId)->where('deleted_at', null)->where('status', 'active')
            ->get()->getResultArray();

        $rows = [];
        foreach ($variantIds as $vi => $v) {
            $zero  = ($vi % 7) === 0;
            $onHand = $zero ? 0 : 5 + (($vi * 3 + $productIndex) % 36);
            $rows[] = ['uuid' => bin2hex(random_bytes(18)), 'variant_id' => (int) $v['id'], 'shop_id' => $shopId, 'on_hand' => $onHand, 'reserved' => 0];
        }
        if ($rows !== []) {
            $db->table('inventory')->insertBatch($rows);
        }

        // publish() below calls ensureListedInVendorShops() itself, which lists this
        // product in every shop the vendor owns — for our 1-vendor-1-shop shops that's
        // exactly this $shopId, so no separate product_shops insert is needed here.
        $this->approval->submit($productId, self::SEED_ACTOR_ID);
        $this->approval->approve($productId, self::SEED_ACTOR_ID);
        $this->approval->publish($productId, self::SEED_ACTOR_ID);
    }

    // ------------------------------------------------------------------ naming

    /** @return list<int> value ids in the same order as $labels */
    private function valueIdsInOrder(int $attributeId, array $labels): array
    {
        $rows = Database::connect()->table('attribute_values')->select('id, value')
            ->where('attribute_id', $attributeId)->where('deleted_at', null)->orderBy('sort_order', 'ASC')->get()->getResultArray();
        $byLabel = [];
        foreach ($rows as $r) {
            $byLabel[$r['value']] = (int) $r['id'];
        }

        return array_values(array_filter(array_map(static fn ($l) => $byLabel[$l] ?? null, $labels)));
    }

    /** @return list<string> $count real, varied, non-repeating-within-shop product titles for this category. */
    private function productNames(string $catKey, int $count): array
    {
        $names = [];
        $seen  = [];
        $i     = 0;
        while (count($names) < $count && $i < $count * 4) {
            $name = $this->buildName($catKey, $i);
            $i++;
            if (isset($seen[$name])) {
                continue; // word-bank cycle repeated before enough unique combos were used; skip the dupe
            }
            $seen[$name] = true;
            $names[] = $name;
        }
        // If the word banks ran out of unique combinations before reaching $count
        // (small banks, large $count), pad with a numbered variant rather than
        // silently returning fewer than asked — still a real, readable title.
        $n = 1;
        while (count($names) < $count) {
            $name = $this->buildName($catKey, $i) . ' - Style ' . $n;
            $names[] = $name;
            $i++;
            $n++;
        }

        return $names;
    }

    private function buildName(string $catKey, int $i): string
    {
        $fabric = self::FABRICS[$i % count(self::FABRICS)];

        return match ($catKey) {
            'tops'    => $fabric . ' ' . self::STYLES_TOP[(int) ($i / count(self::FABRICS)) % count(self::STYLES_TOP)] . ' Top',
            'kurtis'  => $fabric . ' ' . self::STYLES_ETHNIC[(int) ($i / count(self::FABRICS)) % count(self::STYLES_ETHNIC)] . ' Kurti',
            'dresses' => $fabric . ' ' . self::STYLES_DRESS[(int) ($i / count(self::FABRICS)) % count(self::STYLES_DRESS)] . ' Dress',
            'wjeans', 'mtrouser' => self::FITS[$i % count(self::FITS)] . ' ' . $fabric . ' ' . ($catKey === 'wjeans' ? 'Jeans' : 'Trousers'),
            'shirts'  => $fabric . ' ' . self::FITS[(int) ($i / count(self::FABRICS)) % count(self::FITS)] . ' Shirt',
            'tshirts' => $fabric . ' ' . self::STYLES_TOP[(int) ($i / count(self::FABRICS)) % count(self::STYLES_TOP)] . ' T-Shirt',
            'casual'  => self::SHOE_MATERIALS[$i % count(self::SHOE_MATERIALS)] . ' ' . self::SHOE_STYLES[(int) ($i / count(self::SHOE_MATERIALS)) % count(self::SHOE_STYLES)],
            'sandals' => self::SHOE_MATERIALS[$i % count(self::SHOE_MATERIALS)] . ' ' . self::SANDAL_STYLES[(int) ($i / count(self::SHOE_MATERIALS)) % count(self::SANDAL_STYLES)],
            'kids'    => self::KIDS_PRINTS[$i % count(self::KIDS_PRINTS)] . ' ' . self::KIDS_STYLES[(int) ($i / count(self::KIDS_PRINTS)) % count(self::KIDS_STYLES)] . ' (Kids)',
            default   => $fabric . ' Item ' . $i,
        };
    }
}
