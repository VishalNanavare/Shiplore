<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

require_once __DIR__ . '/../_support/MinimalSchema.php';

/**
 * HTTP-level harness for the manufacturer panel.
 *
 * The panel had no FeatureTestTrait coverage at all — its controllers were only ever
 * checked by source-assertion sweeps, which cannot tell whether a route resolves, a
 * guard actually redirects, or a view renders. Phase 1 (identity screens) is the first
 * batch built against real requests.
 *
 * Two things every test in here depends on:
 *
 *  - HTTP_HOST must be a manufacturer host. The group is subdomain-pinned to
 *    manufacturer./mshop., so on any other host these routes are simply not registered
 *    and every assertion would be testing a 404 instead of the controller.
 *  - db_users must exist AND contain the session user. WebAuthFilter re-checks
 *    apiAuthRepository->isActive() on every request and is fail-open only when that
 *    query THROWS; once the table exists, a missing row is a clean false, which logs
 *    the session out. dropUsersTable() in tearDown keeps that from leaking into other
 *    files sharing the SQLite :memory: connection.
 */
final class ManufacturerPanelTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use MinimalSchema;

    /** Owner of manufacturer 1. */
    private const OWNER_UID = 501;
    /** Staff of manufacturer 1, assigned to unit 11 only. */
    private const STAFF_UID = 502;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'manufacturer.shiplore.test');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->ensureUsersTable();
        $this->seedActiveUser(self::OWNER_UID, 'manufacturer', 'Meera Iyer');
        $this->seedActiveUser(self::STAFF_UID, 'manufacturer', 'Unit Staff');

        $this->grant(['mfg.profile.view', 'mfg.profile.manage', 'mfg.notification.view']);

        // Ids are passed in rather than read off the enclosing class: an anonymous class
        // is a distinct class and cannot reach ManufacturerPanelTest's private constants.
        Services::injectMock('manufacturerAccountRepository', new class (self::OWNER_UID, self::STAFF_UID) {
            public function __construct(private int $ownerUid, private int $staffUid) {}

            public function findByOwnerUserId(int $uid): ?array
            {
                return $uid === $this->ownerUid
                    ? ['id' => 1, 'display_name' => 'Precision Tools Pvt Ltd', 'legal_name' => 'Precision Tools Private Limited', 'slug' => 'precision-tools', 'gstin' => '27AAACP1234F1Z5', 'gstin_status' => 'verified', 'status' => 'active', 'party_type' => 'manufacturer', 'logo_media_id' => null]
                    : null;
            }

            public function findStaffManufacturer(int $uid): ?array
            {
                return $uid === $this->staffUid
                    ? ['id' => 1, 'display_name' => 'Precision Tools Pvt Ltd', 'vendor_staff_id' => 77, 'staff_type' => 'unit_manager', 'party_type' => 'manufacturer', 'logo_media_id' => null]
                    : null;
            }

            public function mshopIdsForManufacturer(int $id): array { return [11, 12]; }

            public function mshopIdsForStaff(int $staffId): array { return [11]; }
        });

        // render() -> mshopOptions() reaches this on every manufacturer page.
        Services::injectMock('manufacturerUnitRepository', new class {
            public function list(int $manufacturerId): array
            {
                return [['id' => 11, 'name' => 'Bhiwandi Plant'], ['id' => 12, 'name' => 'Taloja Plant']];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();
        service('superglobals')->unsetServer('HTTP_HOST');
        Services::reset();
        parent::tearDown();
    }

    private function grant(array $perms): void
    {
        Services::injectMock('capabilityRepository', new class ($perms) {
            public function __construct(private array $perms) {}

            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'manufacturer', 'scope_id' => 1, 'attributes' => []]];
            }
        });
    }

    /** @return array<string,mixed> */
    private function ownerSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => self::OWNER_UID, 'user_name' => 'Meera Iyer', 'principal_type' => 'manufacturer'];
    }

    /** @return array<string,mixed> */
    private function staffSession(): array
    {
        return ['isLoggedIn' => true, 'user_id' => self::STAFF_UID, 'user_name' => 'Unit Staff', 'principal_type' => 'manufacturer'];
    }

    private function postSession(array $base): array
    {
        return service('session')->get() + $base;
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    private function mockUserRepo(): object
    {
        $repo = new class {
            public array $profileSaved  = [];
            public array $passwordSaved = [];
            public bool $profileReturns = true;

            public function find(int $id): ?array
            {
                return [
                    'id' => $id, 'name' => 'Meera Iyer', 'email' => 'meera@precision.example',
                    'phone' => '9812345678', 'status' => 'active',
                    // "correct horse" bcrypt hash, so password_verify() has something real to check
                    'password_hash' => password_hash('current-secret', PASSWORD_BCRYPT),
                ];
            }

            public function updateProfile(int $id, string $name, string $email, ?string $phone = null, ?int $actor = null): bool
            {
                $this->profileSaved[] = [$id, $name, $email];

                return $this->profileReturns;
            }

            public function updatePassword(int $id, string $hash): void
            {
                $this->passwordSaved[] = $id;
            }
        };
        Services::injectMock('adminUserRepository', $repo);

        return $repo;
    }

    // ------------------------------------------------------------------ my profile

    public function testMeRequiresLogin(): void
    {
        $this->get('manufacturer/me')->assertRedirect();
    }

    public function testMeIsNotReachableByAVendorPrincipal(): void
    {
        // A vendor owner resolves no manufacturer, so requireManufacturer() bounces
        // them — the party_type gate, not the log-only webAuth pin, is what stops this.
        $this->seedActiveUser(900, 'vendor', 'Vendor Owner');
        $r = $this->withSession(['isLoggedIn' => true, 'user_id' => 900, 'user_name' => 'Vendor Owner', 'principal_type' => 'vendor'])
            ->get('manufacturer/me');

        $r->assertRedirect();
        $this->assertStringContainsString('login', (string) $r->getRedirectUrl());
    }

    public function testMeRendersForOwner(): void
    {
        $this->mockUserRepo();
        $r = $this->withSession($this->ownerSession())->get('manufacturer/me');

        $r->assertStatus(200);
        $this->assertStringContainsString('meera@precision.example', (string) $r->getBody());
    }

    /** Unit staff manage their own login too — this is not an owner-only screen. */
    public function testMeRendersForUnitStaff(): void
    {
        $this->mockUserRepo();
        $this->withSession($this->staffSession())->get('manufacturer/me')->assertStatus(200);
    }

    public function testMeSaveUpdatesTheProfile(): void
    {
        $repo = $this->mockUserRepo();
        $r    = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me', $this->csrf() + ['name' => 'Meera I', 'email' => 'meera.i@precision.example']);

        $r->assertRedirect();
        $this->assertSame([[self::OWNER_UID, 'Meera I', 'meera.i@precision.example']], $repo->profileSaved);
    }

    public function testMeSaveRejectsAnEmptyName(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me', $this->csrf() + ['name' => '   ', 'email' => 'x@y.in'])
            ->assertRedirect();

        $this->assertSame([], $repo->profileSaved, 'a blank name must not reach the repository');
    }

    public function testMeSaveRejectsAMalformedEmail(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me', $this->csrf() + ['name' => 'Meera', 'email' => 'not-an-email'])
            ->assertRedirect();

        $this->assertSame([], $repo->profileSaved);
    }

    public function testPasswordChangeRejectsAWrongCurrentPassword(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me/password', $this->csrf() + ['current_password' => 'wrong', 'new_password' => 'brand-new-secret', 'confirm_password' => 'brand-new-secret'])
            ->assertRedirect();

        $this->assertSame([], $repo->passwordSaved, 'the current password must be verified before any write');
    }

    public function testPasswordChangeRejectsAMismatchedConfirmation(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me/password', $this->csrf() + ['current_password' => 'current-secret', 'new_password' => 'brand-new-secret', 'confirm_password' => 'different-secret'])
            ->assertRedirect();

        $this->assertSame([], $repo->passwordSaved);
    }

    public function testPasswordChangeRejectsAShortPassword(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me/password', $this->csrf() + ['current_password' => 'current-secret', 'new_password' => 'short', 'confirm_password' => 'short'])
            ->assertRedirect();

        $this->assertSame([], $repo->passwordSaved);
    }

    public function testPasswordChangeSucceedsWithTheCorrectCurrentPassword(): void
    {
        $repo = $this->mockUserRepo();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/me/password', $this->csrf() + ['current_password' => 'current-secret', 'new_password' => 'brand-new-secret', 'confirm_password' => 'brand-new-secret'])
            ->assertRedirect();

        $this->assertSame([self::OWNER_UID], $repo->passwordSaved);
    }

    // ------------------------------------------------------------- business profile

    public function testProfileRendersForOwner(): void
    {
        $r = $this->withSession($this->ownerSession())->get('manufacturer/profile');

        $r->assertStatus(200);
        $this->assertStringContainsString('Precision Tools', (string) $r->getBody());
    }

    /** Business identity is the owner's to manage, exactly as on the vendor panel. */
    public function testProfileIsOwnerOnly(): void
    {
        $r = $this->withSession($this->staffSession())->get('manufacturer/profile');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/dashboard', (string) $r->getRedirectUrl());
    }

    /**
     * Asserts on WHICH guard fired, via its flash message, rather than on
     * mediaService never being called.
     *
     * "store() was not called" is true here for two different reasons — the owner
     * check, and the "please choose an image" check immediately after it — because
     * FeatureTestTrait posts no actual file. So that assertion passed whether or not
     * the ownership guard existed at all, and a mutation run caught it doing exactly
     * that. The messages are what separate the two paths.
     */
    public function testLogoUploadIsOwnerOnly(): void
    {
        $spy = new class {
            public int $calls = 0;
            public function store($file, string $ownerType, int $ownerId, int $actorId, string $vis, string $kind): array
            {
                $this->calls++;

                return ['ok' => true, 'id' => 5];
            }
        };
        Services::injectMock('mediaService', $spy);

        $r = $this->withSession($this->postSession($this->staffSession()))
            ->post('manufacturer/profile/logo', $this->csrf());

        $r->assertRedirect();
        $r->assertSessionHas('error', 'Only the owner can change the logo.');
        $this->assertSame(0, $spy->calls, 'unit staff must not be able to replace the business logo');
    }

    /**
     * The control for the test above: an OWNER posting the same empty form gets past
     * the ownership guard and is stopped by the file check instead. Without this, the
     * assertion above could be satisfied by a controller that rejects everyone.
     */
    public function testLogoUploadByOwnerWithNoFileIsStoppedByTheFileCheck(): void
    {
        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/profile/logo', $this->csrf());

        $r->assertRedirect();
        $r->assertSessionHas('error', 'Please choose an image (JPG, PNG, WEBP or GIF).');
    }

    /**
     * The documents screen reads vendor_documents/media_assets through raw queries
     * rather than a mockable repository, so those two tables have to exist for real.
     */
    private function ensureVendorDocumentTables(): void
    {
        $db = $this->schemaConn();
        $db->query('CREATE TABLE IF NOT EXISTS db_vendor_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vendor_id INTEGER NOT NULL, doc_type TEXT, media_id INTEGER,
            status TEXT NOT NULL DEFAULT "uploaded",
            created_by INTEGER, updated_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_media_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT, bucket TEXT, object_key TEXT, owner_type TEXT, owner_id INTEGER,
            mime TEXT, original_name TEXT, size_bytes INTEGER,
            visibility TEXT NOT NULL DEFAULT "private",
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER, created_at TEXT, deleted_at TEXT
        )');
    }

    /** `notifications` is not in MinimalSchema, so any page reaching it needs this. */
    private function mockEmptyNotifications(): void
    {
        Services::injectMock('vendorNotificationRepository', new class {
            public function list(int $userId, int $limit = 50, int $offset = 0): array { return []; }
        });
    }

    // ------------------------------------------------------------ product & variants

    /** Everything the shared product shell reaches for, so the form can render. */
    private function mockProductForm(?array $product = null): void
    {
        Services::injectMock('manufacturerProductRepository', new class ($product) {
            public function __construct(private ?array $product) {}

            public function findById(int $id, int $manufacturerId): ?array
            {
                return $this->product !== null && $id === (int) $this->product['id'] ? $this->product : null;
            }

            public function list(int $m, ?string $s = null, $unit = null): array { return []; }
        });

        Services::injectMock('adminProductRepository', new class {
            public function allowedCategories(int $v): array { return [['id' => 10, 'name' => 'Fasteners', 'slug' => 'fasteners']]; }
            public function formMasters(): array { return ['tax' => [['id' => 4, 'name' => 'GST 18%']], 'units' => [['id' => 1, 'name' => 'Piece']], 'brands' => []]; }
            public function content(int $p): array { return []; }
            public function seo(int $p): array { return []; }
            public function tagsCsv(int $p): string { return ''; }
            public function labelIds(int $p): array { return []; }
            public function faqs(int $p): array { return []; }
            public function customAttributes(int $p): array { return []; }
            public function videos(int $p): array { return []; }
            public function relations(int $p): array { return []; }
        });
        Services::injectMock('mediaRepository', new class {
            public function forProduct(int $p): array { return []; }
            public function documents(int $p): array { return []; }
        });
        Services::injectMock('productBarcodeRepository', new class {
            public function forProduct(int $p): array { return []; }
            public function forVariant(int $v): array { return []; }
        });
    }

    /**
     * The whole point of this screen's rework: it must render the SAME shell the
     * vendor panel renders, not a thinner lookalike. These markers come from
     * partials/_product_form_body — the accordion, the completeness meter and the
     * autosave hook — and none of them existed on the old bespoke 6-field form.
     */
    public function testProductFormRendersTheSharedVendorShell(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create']);
        $this->mockProductForm();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/products/new');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('pf-acc', $body, 'the shared accordion shell must render');
        $this->assertStringContainsString('Completeness', $body);
        $this->assertStringContainsString('data-autosave-base', $body);
    }

    /** All eleven sections, matching vendor — the old form had four fields total. */
    public function testProductFormHasEveryVendorSection(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create']);
        $this->mockProductForm();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/new')->getBody();

        foreach (['Basics', 'Tax &amp; Units', 'Content', 'Media', 'Purchase Policy',
            'Shipping', 'SEO', 'Visibility &amp; Tags', 'FAQ', 'Specs', 'Relations'] as $section) {
            $this->assertStringContainsString($section, $body, "the '{$section}' section is missing from the manufacturer form");
        }
    }

    /** The location picker is a manufacturing unit and must post mshop_id, not shop_id. */
    public function testProductFormPostsTheUnitAsMshopId(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create']);
        $this->mockProductForm();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/new')->getBody();

        $this->assertStringContainsString('name="mshop_id"', $body);
        $this->assertStringNotContainsString('name="shop_id"', $body, 'a manufacturer has units, not shops');
        $this->assertStringContainsString('Manufacturing unit', $body);
        $this->assertStringContainsString('Bhiwandi Plant', $body);
    }

    /**
     * The form must not render controls this panel cannot serve. Three "AI generate"
     * buttons POSTed to manufacturer/products/ai-suggest, which has no route, and the
     * media-library picker opened against an empty base URL.
     */
    public function testTheFormHidesControlsThisPanelCannotServe(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create', 'mfg.product.update']);
        // MUST be the EDIT form: the media-library button is inside `if ($pid)`, so on
        // a new-product form it is absent whatever the flag says — asserting there
        // would pass vacuously, which a mutation run caught it doing.
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/77/edit')->getBody();

        $this->assertStringContainsString('pf-acc', $body, 'sanity: the shared shell must have rendered');
        $this->assertStringNotContainsString('js-ai', $body, 'AI-suggest has no route on this panel');
        $this->assertStringNotContainsString('pfLibOpen', $body, 'the media library picker has no base URL here');
    }

    /**
     * An owner spanning several units has no single on-hand number, so the stock column
     * must be hidden rather than showing a figure that is true for neither unit.
     */
    public function testTheVariantGridHidesStockForAnOwnerSpanningUnits(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $this->mockVariants([[
            'id' => 5, 'sku' => 'B-1', 'barcode' => null, 'mrp' => null,
            'making_price' => '40.00', 'base_price' => '60.00', 'cost_price' => null, 'purchase_price' => null,
            'weight_grams' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null,
            'reorder_level' => null, 'safety_stock' => null,
            'is_default' => 0, 'status' => 'active', 'visibility' => 'vendor', 'attributes' => 'Size: M8',
        ]]);
        $this->mockInventoryService();

        // The owner is allowed units 11 AND 12, so effectiveMshopId() is null.
        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/77/variants')->getBody();
        $this->assertStringNotContainsString('name="stock"', $body, 'no single unit is in scope, so no single stock figure exists');

        // Unit staff are pinned to one unit, so the column is meaningful for them.
        $staffBody = (string) $this->withSession($this->staffSession())->get('manufacturer/products/77/variants')->getBody();
        $this->assertStringContainsString('name="stock"', $staffBody);
    }

    /** Sibling URLs must resolve inside this panel, not the vendor one. */
    public function testProductFormSiblingUrlsStayInThisPanel(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create']);
        $this->mockProductForm();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/new')->getBody();

        $this->assertStringContainsString('manufacturer/products/ai-suggest', $body);
        $this->assertStringNotContainsString('vendor/products/ai-suggest', $body);
        $this->assertStringNotContainsString('admin/products/ai-suggest', $body);
    }

    /**
     * The operator's actual complaint: Variants and Stock exist and work, but nothing
     * on the product list linked to them. Since SKU and price live ONLY on the variants
     * page, a manufacturer could not price a product without typing the URL by hand.
     */
    public function testProductListLinksToVariantsAndStock(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        Services::injectMock('manufacturerProductRepository', new class {
            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'making_price' => '40.00', 'base_price' => '60.00', 'status' => 'draft']];
            }
        });

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products')->getBody();

        $this->assertStringContainsString('manufacturer/products/77/variants', $body);
        $this->assertStringContainsString('manufacturer/products/77/stock', $body);
        $this->assertStringContainsString('manufacturer/products/77/edit', $body);
    }

    /**
     * The list must be usable past a handful of rows: search, sort and paging, plus a
     * visible count so the repository's silent 200-row cap cannot hide anything.
     */
    public function testProductListHasSearchSortAndPaging(): void
    {
        $this->grant(['mfg.product.view']);
        Services::injectMock('manufacturerProductRepository', new class {
            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'making_price' => '40.00', 'base_price' => '60.00', 'status' => 'draft',
                    'variant_count' => 3, 'image_uuid' => 'abc-uuid']];
            }
        });

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products')->getBody();

        $this->assertStringContainsString('dataTables.bootstrap5.min.css', $body, 'the table styling must load');
        $this->assertStringContainsString('jquery.dataTables.min.js', $body);
        $this->assertStringContainsString("DataTable(", $body, 'search/sort/paging must be initialised');
        // Thumbnail and variant count, both from subqueries lifted from the vendor repo.
        $this->assertStringContainsString('media/abc-uuid', $body);
        $this->assertStringContainsString('3 variants', $body);
    }

    /** A single-variant product must not be labelled with a variant badge. */
    public function testASingleVariantProductShowsNoVariantBadge(): void
    {
        $this->grant(['mfg.product.view']);
        Services::injectMock('manufacturerProductRepository', new class {
            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'making_price' => '40.00', 'base_price' => '60.00', 'status' => 'draft',
                    'variant_count' => 1, 'image_uuid' => null]];
            }
        });

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products')->getBody();

        $this->assertStringNotContainsString('1 variants', $body);
    }

    /** A rejected product must be re-submittable, not a permanent dead end. */
    public function testARejectedProductCanBeResubmitted(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        Services::injectMock('manufacturerProductRepository', new class {
            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'making_price' => '40.00', 'base_price' => '60.00', 'status' => 'rejected']];
            }
        });

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products')->getBody();

        $this->assertStringContainsString('manufacturer/products/77/submit', $body);
    }

    /** An unpriced draft must not read as free. */
    public function testUnsetPricesRenderAsDashesNotZero(): void
    {
        $this->grant(['mfg.product.view']);
        Services::injectMock('manufacturerProductRepository', new class {
            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => null, 'category' => null,
                    'making_price' => null, 'base_price' => null, 'status' => 'draft']];
            }
        });

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products')->getBody();

        $this->assertStringNotContainsString('₹0.00', $body, 'an unpriced draft must not read as free');
        $this->assertStringNotContainsString('0.0%', $body, 'margin is meaningless without both prices');
    }

    /** @return object spy covering the trash + bulk surface */
    private function mockTrashRepo(string $status = 'draft'): object
    {
        $repo = new class ($status) {
            public array $deleted   = [];
            public array $restored  = [];
            public array $statusSet = [];
            public bool $deleteOk   = true;

            public function __construct(private string $status) {}

            public function list(int $m, ?string $s = null, $u = null): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'making_price' => '40.00', 'base_price' => '60.00', 'status' => $this->status,
                    'variant_count' => 1, 'image_uuid' => null]];
            }

            public function listTrashed(int $m, $unit = null, int $limit = 200): array
            {
                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'category' => 'Fasteners',
                    'deleted_at' => '2026-08-17 12:00:00']];
            }

            public function findById(int $id, int $m): ?array
            {
                return $id === 77
                    ? ['id' => 77, 'status' => $this->status, 'making_price' => '40.00', 'base_price' => '60.00']
                    : null;
            }

            public function softDeleteDraft(int $id, int $m, ?int $a = null): bool
            {
                $this->deleted[] = [$id, $m];

                return $this->deleteOk;
            }

            public function restoreDraft(int $id, int $m, ?int $a = null): bool
            {
                $this->restored[] = [$id, $m];

                return true;
            }

            public function setStatus(int $id, int $m, string $to, ?int $a = null): bool
            {
                $this->statusSet[] = [$id, $to];

                return true;
            }
        };
        Services::injectMock('manufacturerProductRepository', $repo);

        return $repo;
    }

    public function testTrashListsDeletedDraftsAndOffersRestore(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockTrashRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/products/trash');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('M8 Bolt', $body);
        $this->assertStringContainsString('manufacturer/products/77/restore', $body);
    }

    /** Delete and restore must always carry this tenant's id into the repository. */
    public function testDeleteAndRestoreAreTenantScoped(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockTrashRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/delete', $this->csrf())->assertRedirect();
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/restore', $this->csrf())->assertRedirect();

        $this->assertSame([[77, 1]], $repo->deleted);
        $this->assertSame([[77, 1]], $repo->restored);
    }

    public function testDeleteRequiresTheUpdatePermission(): void
    {
        $this->grant(['mfg.product.view']);
        $repo = $this->mockTrashRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/delete', $this->csrf())->assertRedirect();

        $this->assertSame([], $repo->deleted);
    }

    public function testBulkSubmitMovesEveryPricedDraft(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockTrashRepo('draft');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/bulk', $this->csrf() + ['bulk_action' => 'submit', 'ids' => [77]])
            ->assertRedirect();

        $this->assertSame([[77, 'submitted']], $repo->statusSet);
    }

    /** A bulk submit must not push an already-submitted product through again. */
    public function testBulkSubmitSkipsNonDrafts(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockTrashRepo('published');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/bulk', $this->csrf() + ['bulk_action' => 'submit', 'ids' => [77]])
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet);
    }

    /**
     * The tenant boundary for bulk. A hand-crafted post naming another manufacturer's
     * product must affect nothing — findById() returns null for an id this tenant does
     * not own, so the loop skips it.
     */
    public function testBulkIgnoresProductsThisTenantDoesNotOwn(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockTrashRepo('draft');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/bulk', $this->csrf() + ['bulk_action' => 'submit', 'ids' => [999]])
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet);
    }

    /**
     * A bulk submit must re-check prices, exactly as the single-product path does.
     * Otherwise it becomes the way to push an unpriced product into the approval queue.
     */
    public function testBulkSubmitSkipsAProductThatFailsThePriceRule(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = new class {
            public array $statusSet = [];

            public function list(int $m, ?string $s = null, $u = null): array { return []; }

            public function findById(int $id, int $m): ?array
            {
                // selling BELOW making — the invariant ManufacturerPricing enforces.
                return ['id' => 77, 'status' => 'draft', 'making_price' => '90.00', 'base_price' => '60.00'];
            }

            public function setStatus(int $id, int $m, string $to, ?int $a = null): bool
            {
                $this->statusSet[] = [$id, $to];

                return true;
            }
        };
        Services::injectMock('manufacturerProductRepository', $repo);

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/bulk', $this->csrf() + ['bulk_action' => 'submit', 'ids' => [77]])
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet, 'selling below making must never reach approval');
    }

    public function testBulkRejectsAnUnknownAction(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockTrashRepo('draft');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/bulk', $this->csrf() + ['bulk_action' => 'publish_everything', 'ids' => [77]])
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet);
        $this->assertSame([], $repo->deleted);
    }

    /** @return object the product repository spy, for asserting setStatus() calls */
    private function mockProductStatus(string $status): object
    {
        $repo = new class ($status) {
            public array $statusSet = [];

            public function __construct(private string $status) {}

            public function findById(int $id, int $m): ?array
            {
                return $id === 77
                    ? ['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'status' => $this->status,
                        'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]
                    : null;
            }

            public function list(int $m, ?string $s = null, $unit = null): array { return []; }

            public function setStatus(int $id, int $m, string $to, ?int $a = null): bool
            {
                $this->statusSet[] = [$id, $to];

                return true;
            }
        };
        Services::injectMock('manufacturerProductRepository', $repo);

        return $repo;
    }

    public function testAnApprovedProductCanBePublished(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockProductStatus('approved');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/publish', $this->csrf())
            ->assertRedirect();

        $this->assertSame([[77, 'published']], $repo->statusSet);
    }

    public function testAPublishedProductCanBeUnpublished(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockProductStatus('published');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/unpublish', $this->csrf())
            ->assertRedirect();

        $this->assertSame([[77, 'unpublished']], $repo->statusSet);
    }

    /**
     * The approval gate. Publishing a draft directly would let a manufacturer put
     * unreviewed goods in front of B2B buyers, skipping the admin approval this
     * platform is built around.
     */
    public function testADraftCannotBePublishedDirectly(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockProductStatus('draft');

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/publish', $this->csrf());

        $r->assertRedirect();
        $this->assertSame([], $repo->statusSet, 'an unapproved product must never go live');
    }

    /** ...and an already-live product cannot be "published" again into a new state. */
    public function testAnUnapprovedStatusCannotBeUnpublished(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockProductStatus('draft');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/unpublish', $this->csrf())
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet);
    }

    public function testPublishRequiresTheUpdatePermission(): void
    {
        $this->grant(['mfg.product.view']); // view only
        $repo = $this->mockProductStatus('approved');

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/publish', $this->csrf())
            ->assertRedirect();

        $this->assertSame([], $repo->statusSet);
    }

    /** Another tenant's product id must not be publishable. */
    public function testPublishRejectsAProductThisTenantDoesNotOwn(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $repo = $this->mockProductStatus('approved'); // findById returns null for id 99

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/99/publish', $this->csrf());

        $r->assertRedirect();
        $this->assertSame([], $repo->statusSet);
    }

    /** New products must land on Variants, where SKU and price actually live. */
    public function testCreatingAProductLandsOnTheVariantsPage(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.create']);
        $this->mockProductForm();
        Services::injectMock('manufacturerProductRepository', new class {
            public function create(int $m, array $d, ?int $a, ?int $mshopId): array { return ['ok' => true, 'id' => 77, 'error' => '']; }
            public function findById(int $id, int $m): ?array { return null; }
            public function list(int $m, ?string $s = null, $u = null): array { return []; }
        });

        $r = $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/products/store', $this->csrf() + [
            'title' => 'M8 Bolt', 'category_id' => '10', 'mshop_id' => '11',
            'making_price' => '40', 'base_price' => '60',
        ]);

        $r->assertRedirect();
        $this->assertStringContainsString(
            'manufacturer/products/77/variants',
            (string) $r->getRedirectUrl(),
            'landing on /edit strands the user with an unpriced product and no visible way to price it',
        );
    }

    private function mockVariants(array $variants = []): object
    {
        $spy = new class ($variants) {
            public array $variants;
            public array $generated = [];
            public array $bulk      = [];

            public function __construct(array $v) { $this->variants = $v; }

            public function cleanupEmptyDefault(int $p): void {}
            public function definingAttributes(int $c): array
            {
                return [['id' => 1, 'code' => 'size', 'name' => 'Size', 'type' => 'select', 'values' => [['id' => 5, 'value' => 'M8', 'sort_order' => 1]]]];
            }
            public function listWithValues(int $p): array { return $this->variants; }
            public function findVariant(int $id): ?array
            {
                return ['id' => $id, 'product_id' => 77, 'vendor_id' => 1, 'making_price' => '40.00', 'base_price' => '60.00'];
            }
            public function generate(int $pid, int $vendorId, array $sel, array $base, ?int $a = null): int
            {
                $this->generated[] = ['pid' => $pid, 'vendor' => $vendorId, 'sel' => $sel, 'base' => $base];

                return 2;
            }
            public function updateVariant(int $id, int $v, array $d, ?int $a = null): bool { return true; }
            public function bulkUpdate(array $ids, int $v, string $f, string $val, ?int $a = null): int
            {
                $this->bulk[] = [$ids, $f, $val];

                return count($ids);
            }
        };
        Services::injectMock('productVariantRepository', $spy);

        return $spy;
    }

    public function testVariantsPageRendersMakingAndSellingPrice(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        // Column list copied from ProductVariantRepository::listWithValues()'s select()
        // plus the derived `attributes` string it appends — a mock shaped from memory
        // lets the view read keys the real query never returns.
        $this->mockVariants([[
            'id' => 5, 'sku' => 'B-1', 'barcode' => null,
            'mrp' => null, 'making_price' => '40.00', 'base_price' => '60.00',
            'cost_price' => null, 'purchase_price' => null,
            'weight_grams' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null,
            'reorder_level' => null, 'safety_stock' => null,
            'is_default' => 0, 'status' => 'active', 'visibility' => 'vendor',
            'attributes' => 'Size: M8',
        ]]);

        $r = $this->withSession($this->ownerSession())->get('manufacturer/products/77/variants');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('Making price', $body);
        $this->assertStringContainsString('name="making_price"', $body);
        $this->assertStringContainsString('Selling price', $body);
        // MRP is meaningless for a manufacturer — mrp stays 0 on these products.
        $this->assertStringNotContainsString('name="mrp"', $body);
    }

    /** The grid's stock box must move stock through the MANUFACTURER service. */
    public function testVariantGridStockEditGoesThroughTheManufacturerService(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $this->mockVariants();
        $svc = $this->mockInventoryService();   // levels() reports 120 on hand

        $this->withSession($this->postSession($this->staffSession()))
            ->post('manufacturer/variants/5/update', $this->csrf() + [
                'sku' => 'B-1', 'making_price' => '40', 'base_price' => '60', 'stock' => '150',
            ])->assertRedirect();

        // A DELTA, not an absolute set: the mfg ledger must explain the movement.
        $this->assertSame([[5, 11, 30.0, 'correction']], $svc->adjusted);
    }

    /** An unchanged stock box must not write a zero-delta movement. */
    public function testVariantGridStockEditIsANoOpWhenUnchanged(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $this->mockVariants();
        $svc = $this->mockInventoryService();

        $this->withSession($this->postSession($this->staffSession()))
            ->post('manufacturer/variants/5/update', $this->csrf() + [
                'sku' => 'B-1', 'making_price' => '40', 'base_price' => '60', 'stock' => '120',
            ])->assertRedirect();

        $this->assertSame([], $svc->adjusted, 'no change means no ledger entry');
    }

    public function testVariantsPageIsTenantScoped(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(); // findById returns null for every id
        $this->mockVariants();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/products/77/variants');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/products', (string) $r->getRedirectUrl());
    }

    public function testVariantGenerateForcesTheTenantAndKeepsThePriceRule(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $spy = $this->mockVariants();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/variants/generate', $this->csrf() + [
                'sku_prefix' => 'BOLT', 'making_price' => '40', 'base_price' => '60',
                'sel' => [1 => [5, 6]],
            ])->assertRedirect();

        $this->assertCount(1, $spy->generated);
        $this->assertSame(1, $spy->generated[0]['vendor'], 'the tenant must come from the session, never the post');
        $this->assertSame([1 => [5, 6]], $spy->generated[0]['sel']);
    }

    /** The invariant must hold on the variant grid too, or it is an open side door. */
    public function testVariantGenerateRejectsASellingPriceBelowMaking(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $spy = $this->mockVariants();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/variants/generate', $this->csrf() + [
                'sku_prefix' => 'BOLT', 'making_price' => '90', 'base_price' => '60',
                'sel' => [1 => [5]],
            ])->assertRedirect();

        $this->assertSame([], $spy->generated, 'selling below making must never reach the repository');
    }

    public function testVariantBulkPriceUpdateIsAlsoValidated(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $spy = $this->mockVariants();

        // findVariant() reports making 40 / selling 60; bulk-setting selling to 10
        // would invert the rule.
        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/variants/bulk', $this->csrf() + [
                'ids' => [5], 'field' => 'base_price', 'value' => '10',
            ])->assertRedirect();

        $this->assertSame([], $spy->bulk, 'a bulk price set must be validated like any other');
    }

    public function testVariantBulkUpdateAppliesWhenValid(): void
    {
        $this->grant(['mfg.product.view', 'mfg.product.update']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $spy = $this->mockVariants();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/variants/bulk', $this->csrf() + [
                'ids' => [5], 'field' => 'base_price', 'value' => '99',
            ])->assertRedirect();

        $this->assertSame([[[5], 'base_price', '99']], $spy->bulk);
    }

    // ------------------------------------------------------------------ governance

    /** @return array{0:object,1:object} [engine spy, repository spy] */
    private function mockGovernance(): array
    {
        $engine = new class {
            public array $submitted = [];
            public array $decided   = [];
            public array $submitReturns = ['ok' => true, 'id' => 1, 'status' => 'pending_l1'];
            public array $decideReturns = ['ok' => true, 'status' => 'approved'];

            public function submit(array $in, array $actor, $now = null): array
            {
                $this->submitted[] = [$in, $actor];

                return $this->submitReturns;
            }

            public function decide(int $id, array $actor, string $decision, ?string $reason = null, $now = null): array
            {
                $this->decided[] = [$id, $actor, $decision, $reason];

                return $this->decideReturns;
            }
        };
        Services::injectMock('changeRequestEngine', $engine);

        $repo = new class {
            public array $seenVendorIds = [];

            public function pendingForVendor(int $vendorId, ?string $status = null): array
            {
                $this->seenVendorIds[] = $vendorId;

                return [[
                    'id' => 4, 'entity_type' => 'staff', 'action' => 'create', 'entity_id' => null,
                    'status' => 'pending_l1', 'requester_name' => 'Unit Staff', 'requester_role' => 'manager',
                    'created_at' => '2026-08-17 11:00:00', 'sla_due_at' => '2026-08-19 11:00:00',
                    'payload_new' => ['data' => ['name' => 'Proposed Hire']], 'reason' => null,
                ]];
            }

            public function listForRequester(int $userId): array
            {
                return [[
                    'id' => 4, 'entity_type' => 'staff', 'action' => 'create', 'entity_id' => null,
                    'status' => 'pending_l1', 'reason' => null, 'created_at' => '2026-08-17 11:00:00',
                ]];
            }
        };
        Services::injectMock('changeRequestRepository', $repo);

        return [$engine, $repo];
    }

    public function testApprovalsInboxRendersForOwner(): void
    {
        $this->grant(['mfg.request.approve']);
        [, $repo] = $this->mockGovernance();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/approvals');

        $r->assertStatus(200);
        $this->assertStringContainsString('Proposed Hire', (string) $r->getBody());
        // Scoped to THIS manufacturer's id, not to some other tenant's.
        $this->assertSame([1], $repo->seenVendorIds);
    }

    public function testApprovalsInboxIsDeniedWithoutAuthority(): void
    {
        $this->grant(['mfg.staff.view']); // no mfg.request.approve, and not the owner
        $this->mockGovernance();

        $r = $this->withSession($this->staffSession())->get('manufacturer/approvals');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/dashboard', (string) $r->getRedirectUrl());
    }

    /**
     * The engine's tenant-owner level is named 'vendor'. Passing 'manufacturer' would
     * match no level and every decision would fail with wrong_approver_role, so this
     * pins the mapping rather than leaving it to a comment.
     */
    public function testDecisionsUseTheEnginesTenantOwnerRole(): void
    {
        $this->grant(['mfg.request.approve']);
        [$engine] = $this->mockGovernance();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/approvals/4/decide', $this->csrf() + ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertCount(1, $engine->decided);
        [$id, $actor, $decision] = $engine->decided[0];
        $this->assertSame(4, $id);
        $this->assertSame('approved', $decision);
        $this->assertSame('vendor', $actor['role'], "the engine's tenant-owner level is 'vendor'");
        $this->assertSame(1, $actor['vendor_id'], 'the request must be scoped to this manufacturer');
    }

    public function testAFailedDecisionSurfacesTheEnginesReason(): void
    {
        $this->grant(['mfg.request.approve']);
        [$engine] = $this->mockGovernance();
        $engine->decideReturns = ['ok' => false, 'error' => 'self_approval_forbidden'];

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/approvals/4/decide', $this->csrf() + ['decision' => 'approved']);

        $r->assertRedirect();
        $r->assertSessionHas('error', 'You cannot decide your own request.');
    }

    public function testMyRequestsRenders(): void
    {
        $this->grant([]);
        $this->mockGovernance();

        $r = $this->withSession($this->staffSession())->get('manufacturer/requests');

        $r->assertStatus(200);
        $this->assertStringContainsString('My Requests', (string) $r->getBody());
        $this->assertStringContainsString('pending l1', (string) $r->getBody());
    }

    /** A manager who may only propose must not write to the database. */
    public function testAManagerStaffCreateBecomesAChangeRequest(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.request']);
        [$engine] = $this->mockGovernance();
        $repo     = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->staffSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'Proposed Hire', 'email' => 'hire@precision.example',
            'staff_type' => 'store_keeper', 'mshop_ids' => [11],
        ])->assertRedirect();

        $this->assertSame([], $repo->created, 'a proposer must not write staff directly');
        $this->assertCount(1, $engine->submitted);
        [$in, $actor] = $engine->submitted[0];
        // 'mfg_staff', NOT 'staff': the bare staff.* chain keys are registered against
        // vendorStaffRepository, so approving under them would write this manufacturer's
        // hire into the VENDOR staff tables.
        $this->assertSame('mfg_staff', $in['entity_type']);
        $this->assertSame('create', $in['action']);
        $this->assertSame(1, $in['vendor_id'], 'the request belongs to this manufacturer');
        // shop_id must be null — it is a foreign key to `shops`, and an mshop is not one.
        $this->assertNull($in['shop_id']);
        $this->assertSame('manager', $actor['role']);
    }

    /** ...while the owner still writes directly, with no request raised. */
    public function testTheOwnerStillCreatesStaffDirectly(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        [$engine] = $this->mockGovernance();
        $repo     = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'Direct Hire', 'email' => 'direct@precision.example',
            'staff_type' => 'store_keeper', 'mshop_ids' => [11],
        ])->assertRedirect();

        $this->assertCount(1, $repo->created);
        $this->assertSame([], $engine->submitted, 'the owner does not go through approval');
    }

    /** Someone with neither authority is refused outright. */
    public function testStaffWritesAreRefusedWithoutEitherAuthority(): void
    {
        $this->grant(['mfg.staff.view']); // view only
        [$engine] = $this->mockGovernance();
        $repo     = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->staffSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'Nope', 'email' => 'nope@precision.example',
            'staff_type' => 'store_keeper', 'mshop_ids' => [11],
        ])->assertRedirect();

        $this->assertSame([], $repo->created);
        $this->assertSame([], $engine->submitted);
    }

    // ------------------------------------------------------- deliveries & riders

    private function mockDeliveryRepo(): object
    {
        $repo = new class {
            public array $assigned    = [];
            public array $transitions = [];
            public array $ridersAdded = [];

            public function list(int $m, array $units, ?string $status = null, int $limit = 200): array
            {
                return [[
                    'id' => 3, 'po_id' => 90, 'mshop_id' => 11, 'rider_user_id' => null,
                    'mode' => 'self', 'status' => 'pending', 'eta_at' => null, 'assigned_at' => null,
                    'delivered_at' => null, 'failure_reason' => null,
                    'po_no' => 'PO-2026-0090', 'grand_total' => '11800.0000', 'buyer_vendor_id' => 2,
                    'unit_name' => 'Bhiwandi Plant', 'rider_name' => null, 'rider_phone' => null,
                    'buyer_name' => 'Sole Mate Footwear',
                ]];
            }

            public function riders(int $m): array
            {
                return [['id' => 1, 'user_id' => 700, 'vehicle_type' => 'van', 'vehicle_no' => 'MH04 AB 1234', 'availability' => 'offline', 'status' => 'active', 'name' => 'Suresh', 'phone' => '9800000009']];
            }

            public function assignRider(int $d, int $m, int $rider, ?int $a = null): array
            {
                $this->assigned[] = [$d, $rider];

                return ['ok' => true, 'error' => ''];
            }

            public function transition(int $d, int $m, string $to, ?string $reason, ?int $a = null): array
            {
                $this->transitions[] = [$d, $to, $reason];

                return ['ok' => true, 'error' => ''];
            }

            public function phoneExists(string $p): bool { return $p === '9999999999'; }

            public function addRider(int $m, array $d, ?int $a = null): ?int
            {
                $this->ridersAdded[] = $d;

                return 12;
            }
        };
        Services::injectMock('manufacturerDeliveryRepository', $repo);

        return $repo;
    }

    public function testDeliveriesScreenRenders(): void
    {
        $this->grant(['mfg.delivery.assign']);
        $this->mockDeliveryRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/deliveries');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('PO-2026-0090', $body);
        $this->assertStringContainsString('Sole Mate Footwear', $body);
    }

    public function testDeliveriesAreDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']);
        $this->mockDeliveryRepo();

        $this->withSession($this->ownerSession())->get('manufacturer/deliveries')->assertRedirect();
    }

    public function testAssigningARiderPassesThroughToTheRepository(): void
    {
        $this->grant(['mfg.delivery.assign']);
        $repo = $this->mockDeliveryRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/deliveries/3/assign', $this->csrf() + ['rider_user_id' => '700'])
            ->assertRedirect();

        $this->assertSame([[3, 700]], $repo->assigned);
    }

    public function testDeliveryTransitionPassesTheTargetState(): void
    {
        $this->grant(['mfg.delivery.assign']);
        $repo = $this->mockDeliveryRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/deliveries/3/delivered', $this->csrf())
            ->assertRedirect();

        $this->assertSame(3, $repo->transitions[0][0]);
        $this->assertSame('delivered', $repo->transitions[0][1]);
    }

    public function testRiderRosterRenders(): void
    {
        $this->grant(['mfg.rider.manage']);
        $this->mockDeliveryRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/riders');

        $r->assertStatus(200);
        $this->assertStringContainsString('Suresh', (string) $r->getBody());
    }

    /** Minting a rider login is owner-only, like hiring staff. */
    public function testAddRiderIsOwnerOnly(): void
    {
        $this->grant(['mfg.rider.manage']);
        $repo = $this->mockDeliveryRepo();

        $r = $this->withSession($this->postSession($this->staffSession()))
            ->post('manufacturer/riders', $this->csrf() + ['name' => 'New Rider', 'phone' => '9812345678']);

        $r->assertRedirect();
        $this->assertSame([], $repo->ridersAdded);
    }

    public function testAddRiderRejectsAMalformedPhone(): void
    {
        $this->grant(['mfg.rider.manage']);
        $repo = $this->mockDeliveryRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/riders', $this->csrf() + ['name' => 'New Rider', 'phone' => '123'])
            ->assertRedirect();

        $this->assertSame([], $repo->ridersAdded);
    }

    public function testAddRiderRejectsAnAlreadyRegisteredPhone(): void
    {
        $this->grant(['mfg.rider.manage']);
        $repo = $this->mockDeliveryRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/riders', $this->csrf() + ['name' => 'New Rider', 'phone' => '9999999999'])
            ->assertRedirect();

        $this->assertSame([], $repo->ridersAdded);
    }

    public function testAddRiderCreatesTheRider(): void
    {
        $this->grant(['mfg.rider.manage']);
        $repo = $this->mockDeliveryRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/riders', $this->csrf() + ['name' => 'New Rider', 'phone' => '9812345678', 'vehicle_type' => 'van'])
            ->assertRedirect();

        $this->assertCount(1, $repo->ridersAdded);
        $this->assertSame('New Rider', $repo->ridersAdded[0]['name']);
        $this->assertSame('van', $repo->ridersAdded[0]['vehicle_type']);
    }

    // --------------------------------------------------------- unit serviceability

    private function mockUnitRepo(): object
    {
        $repo = new class {
            public array $updated = [];

            public function list(int $m): array
            {
                return [['id' => 11, 'name' => 'Bhiwandi Plant'], ['id' => 12, 'name' => 'Taloja Plant']];
            }

            public function findById(int $id, int $m): ?array
            {
                return in_array($id, [11, 12], true)
                    ? ['id' => $id, 'name' => 'Bhiwandi Plant', 'gstin' => null, 'address_json' => '{}',
                        'pincode' => '421302', 'state_code' => 'MH', 'latitude' => null, 'longitude' => null,
                        'delivery_enabled' => 0, 'pickup_enabled' => 1, 'delivery_radius_km' => null,
                        'prep_time_min' => null, 'min_order_value' => null, 'delivery_fee' => null,
                        'free_delivery_above' => null, 'status' => 'active']
                    : null;
            }

            public function update(int $id, int $m, array $d, ?int $a = null): bool
            {
                $this->updated[] = $d;

                return true;
            }
        };
        Services::injectMock('manufacturerUnitRepository', $repo);

        return $repo;
    }

    /** One unit, whole: what it makes, who works there, what it holds. */
    public function testUnitConsoleRenders(): void
    {
        $this->grant(['mfg.unit.view', 'mfg.unit.update']);
        $this->mockUnitRepo();
        $seenUnit = null;
        Services::injectMock('manufacturerProductRepository', new class ($seenUnit) {
            public $seenUnit;
            public function __construct(&$seen) { $this->seenUnit = &$seen; }

            public function list(int $m, ?string $s = null, $unit = null): array
            {
                $this->seenUnit = $unit;

                return [['id' => 77, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'status' => 'published']];
            }
        });
        Services::injectMock('manufacturerInventoryService', new class {
            public function levelsForUnits(int $m, array $u, int $l = 500): array
            {
                return [['variant_id' => 5, 'title' => 'M8 Bolt', 'sku' => 'B-1', 'on_hand' => '120.000', 'status' => 'in_stock']];
            }
        });
        Services::injectMock('manufacturerStaffRepository', new class {
            public function staffWithUnits(int $m): array
            {
                return [
                    ['id' => 31, 'name' => 'Ravi Kumar', 'staff_type' => 'unit_manager', 'units' => 'Bhiwandi Plant'],
                    ['id' => 32, 'name' => 'Other Person', 'staff_type' => 'store_keeper', 'units' => 'Taloja Plant'],
                ];
            }
        });

        $r = $this->withSession($this->ownerSession())->get('manufacturer/units/11');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('M8 Bolt', $body, 'products made here');
        $this->assertStringContainsString('120', $body, 'stock held here');
        $this->assertStringContainsString('Ravi Kumar', $body, 'staff assigned here');
        $this->assertStringNotContainsString('Other Person', $body, "another unit's staff must not appear");
        // The catalogue must be scoped to THIS unit, not the whole manufacturer's.
        $this->assertSame(11, $seenUnit, 'the console must ask for this unit only');
    }

    /** A unit belonging to someone else must not be openable. */
    public function testUnitConsoleRejectsAForeignUnit(): void
    {
        $this->grant(['mfg.unit.view']);
        $this->mockUnitRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/units/99');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/units', (string) $r->getRedirectUrl());
    }

    /**
     * ...and a unit that DOES belong to the manufacturer but is not assigned to this
     * staff member must also be refused. Unit 12 exists and findById() returns it, so
     * only requireMshopAccess() stands between a store keeper and another plant —
     * which is why the foreign-unit test above cannot cover this on its own.
     */
    public function testUnitConsoleRejectsAUnitThisStaffIsNotAssignedTo(): void
    {
        $this->grant(['mfg.unit.view']);
        $this->mockUnitRepo();   // staff 502 is assigned to unit 11 only

        $r = $this->withSession($this->staffSession())->get('manufacturer/units/12');

        $r->assertRedirect();
        $r->assertSessionHas('error', 'Unit not found.');
    }

    public function testUnitEditShowsDeliverySettingsWithThePermission(): void
    {
        $this->grant(['mfg.unit.view', 'mfg.unit.update', 'mfg.unit.serviceability']);
        $this->mockUnitRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/units/11/edit');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('name="delivery_enabled"', $body);
        $this->assertStringContainsString('name="delivery_radius_km"', $body);
    }

    /** Correcting an address is a smaller decision than changing delivery promises. */
    public function testUnitEditHidesDeliverySettingsWithoutThePermission(): void
    {
        $this->grant(['mfg.unit.view', 'mfg.unit.update']); // no serviceability
        $this->mockUnitRepo();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/units/11/edit')->getBody();

        $this->assertStringNotContainsString('name="delivery_enabled"', $body);
        $this->assertStringNotContainsString('name="delivery_radius_km"', $body);
    }

    public function testUnitUpdateWritesServiceabilityWhenPermitted(): void
    {
        $this->grant(['mfg.unit.view', 'mfg.unit.update', 'mfg.unit.serviceability']);
        $repo = $this->mockUnitRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/units/11/update', $this->csrf() + [
                'name' => 'Bhiwandi Plant', 'delivery_enabled' => '1', 'delivery_radius_km' => '25',
            ])->assertRedirect();

        $this->assertCount(1, $repo->updated);
        $this->assertTrue($repo->updated[0]['serviceability'] ?? false);
    }

    /**
     * Without the permission the flag must be absent, so the repository leaves those
     * columns alone — an address edit cannot silently blank a unit's delivery setup.
     */
    public function testUnitUpdateWithoutPermissionCannotTouchServiceability(): void
    {
        $this->grant(['mfg.unit.view', 'mfg.unit.update']);
        $repo = $this->mockUnitRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/units/11/update', $this->csrf() + [
                'name' => 'Bhiwandi Plant',
                // Hand-crafted: both the payload AND the flag itself are posted.
                'delivery_enabled' => '1', 'delivery_radius_km' => '25', 'serviceability' => '1',
            ])->assertRedirect();

        $this->assertCount(1, $repo->updated);
        $this->assertArrayNotHasKey(
            'serviceability',
            $repo->updated[0],
            'the flag must be decided by the controller, never accepted from input',
        );
    }

    // ---------------------------------------------------------------------- stock

    private function mockInventoryService(): object
    {
        $svc = new class {
            public array $produced = [];
            public array $adjusted = [];
            public array $shipped  = [];

            public function levelsForUnits(int $m, array $units, int $limit = 500): array
            {
                return [[
                    'variant_id' => 5, 'mshop_id' => 11, 'on_hand' => '120.000', 'reserved' => '0.000',
                    'available' => '120.000', 'reorder_level' => null, 'status' => 'in_stock',
                    'sku' => 'B-1', 'product_id' => 77, 'title' => 'M8 Bolt', 'unit_name' => 'Bhiwandi Plant',
                ]];
            }

            public function levels(int $v, int $u): array
            {
                return ['id' => 1, 'on_hand' => '120.000', 'reserved' => '0.000', 'available' => '120.000', 'reorder_level' => null, 'status' => 'in_stock'];
            }

            public function ledger(int $v, int $u, int $limit = 50): array
            {
                return [['id' => 1, 'movement_type' => 'production', 'qty' => '120.000', 'balance_after' => '120.000', 'ref_type' => 'batch', 'ref_id' => 1, 'note' => 'produced', 'created_at' => '2026-08-17 08:00:00']];
            }

            public function produce(int $v, int $u, float $q, float $c, array $o = [], ?int $a = null): bool
            {
                $this->produced[] = [$v, $u, $q];

                return true;
            }

            public function adjust(int $v, int $u, float $d, string $r, string $n = '', ?int $a = null): bool
            {
                $this->adjusted[] = [$v, $u, $d, $r];

                return true;
            }

            public function shipForPurchaseOrder(int $v, int $u, float $q, int $po, ?int $a = null): bool
            {
                $this->shipped[] = [$v, $u, $q, $po];

                return true;
            }
        };
        Services::injectMock('manufacturerInventoryService', $svc);

        return $svc;
    }

    public function testStockGridRenders(): void
    {
        $this->grant(['mfg.inventory.view']);
        $this->mockInventoryService();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/inventory');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('M8 Bolt', $body);
        $this->assertStringContainsString('Bhiwandi Plant', $body);
    }

    public function testStockGridIsDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']);
        $this->mockInventoryService();

        $this->withSession($this->ownerSession())->get('manufacturer/inventory')->assertRedirect();
    }

    /**
     * The grid's Manage link must carry its row's unit.
     *
     * The grid has one row per (variant, unit). The link dropped mshop_id, so the stock
     * page fell back to allowedMshopIds()[0] — first row of a query with no ORDER BY.
     * An owner filtered to Plant B, clicked Manage, recorded production, and it landed in
     * Plant A with a success message.
     */
    public function testTheStockGridLinkCarriesItsRowsUnit(): void
    {
        $this->grant(['mfg.inventory.view']);
        $this->mockInventoryService();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/inventory')->getBody();

        $this->assertMatchesRegularExpression(
            '#/stock\?mshop_id=11#',
            $body,
            'Manage must open the unit whose row was clicked',
        );
    }

    /** The stock page lists variants; this stands in for the real repository. */
    private function mockVariantList(): void
    {
        Services::injectMock('productVariantRepository', new class {
            public function listWithValues(int $productId): array
            {
                return [['id' => 5, 'sku' => 'B-1', 'attributes' => '', 'is_default' => 1]];
            }
        });
    }

    /**
     * The stock page must SHOW which unit it writes to when there is a choice.
     *
     * mshop_id was a hidden input, so a mis-targeted write was invisible until a stock
     * count disagreed. This owner spans units 11 and 12, so a picker must render.
     */
    public function testTheStockPageLetsAnOwnerChooseTheUnit(): void
    {
        $this->grant(['mfg.inventory.view', 'mfg.inventory.adjust']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $this->mockInventoryService();
        $this->mockVariantList();

        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/77/stock')->getBody();

        $this->assertMatchesRegularExpression(
            '/<select[^>]+name="mshop_id"/',
            $body,
            'an owner spanning units must be able to see and pick the destination',
        );
        $this->assertStringContainsString('Taloja Plant', $body, 'the other unit must be offered');
    }

    /** A unit id from the query string must still be checked against allowed units. */
    public function testAForeignUnitInTheQueryStringIsIgnored(): void
    {
        $this->grant(['mfg.inventory.view', 'mfg.inventory.adjust']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $this->mockInventoryService();
        $this->mockVariantList();

        // 99 belongs to no unit of this manufacturer.
        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/products/77/stock?mshop_id=99')->getBody();

        $this->assertStringNotContainsString('value="99" selected', $body, 'a foreign unit must never be selected');
    }

    public function testProduceRecordsStockAgainstTheChosenUnit(): void
    {
        $this->grant(['mfg.inventory.view', 'mfg.inventory.adjust']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $svc = $this->mockInventoryService();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/stock/produce', $this->csrf() + ['variant_id' => 5, 'mshop_id' => 11, 'qty' => '50', 'making_cost' => '40'])
            ->assertRedirect();

        $this->assertSame([[5, 11, 50.0]], $svc->produced);
    }

    /** A unit belonging to someone else must be refused, not written. */
    public function testProduceRejectsAForeignUnit(): void
    {
        $this->grant(['mfg.inventory.view', 'mfg.inventory.adjust']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $svc = $this->mockInventoryService();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/stock/produce', $this->csrf() + ['variant_id' => 5, 'mshop_id' => 99, 'qty' => '50'])
            ->assertRedirect();

        $this->assertSame([], $svc->produced, 'unit 99 is not this manufacturer\'s');
    }

    public function testStockWritesAreDeniedWithoutTheAdjustPermission(): void
    {
        $this->grant(['mfg.inventory.view']); // view only
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $svc = $this->mockInventoryService();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/stock/adjust', $this->csrf() + ['variant_id' => 5, 'mshop_id' => 11, 'qty_delta' => '-5', 'reason' => 'damage'])
            ->assertRedirect();

        $this->assertSame([], $svc->adjusted);
    }

    public function testAdjustAppliesASignedDelta(): void
    {
        $this->grant(['mfg.inventory.view', 'mfg.inventory.adjust']);
        $this->mockProductForm(['id' => 77, 'title' => 'M8 Bolt', 'category_id' => 10, 'making_price' => '40.00', 'base_price' => '60.00', 'mshop_id' => 11]);
        $svc = $this->mockInventoryService();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/products/77/stock/adjust', $this->csrf() + ['variant_id' => 5, 'mshop_id' => 11, 'qty_delta' => '-5', 'reason' => 'damage'])
            ->assertRedirect();

        $this->assertSame([[5, 11, -5.0, 'damage']], $svc->adjusted);
    }

    // ------------------------------------------------------------ media & documents

    public function testMediaLibraryRendersForPermittedUser(): void
    {
        $this->grant(['mfg.media.view', 'mfg.media.upload']);
        Services::injectMock('mediaLibraryRepository', new class {
            public function listForOwner(string $t, int $id, int $limit = 500): array
            {
                return [['id' => 8, 'object_key' => 'vendors/1/media/2026/08/abc.png', 'original_name' => 'spec-sheet.png', 'mime' => 'image/png', 'size_bytes' => 20480, 'created_at' => '2026-08-17 10:00:00']];
            }
            public function countForOwner(string $t, int $id): int { return 1; }
        });

        $r = $this->withSession($this->ownerSession())->get('manufacturer/media');

        $r->assertStatus(200);
        $this->assertStringContainsString('spec-sheet.png', (string) $r->getBody());
    }

    public function testMediaLibraryIsDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']);

        $r = $this->withSession($this->ownerSession())->get('manufacturer/media');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/dashboard', (string) $r->getRedirectUrl());
    }

    /**
     * mediaLibraryRepository::findById() is NOT tenant-scoped — it looks an asset up by
     * primary key alone. Without the ownership re-check in the controller, one
     * manufacturer could open another's private files by guessing an id.
     */
    public function testMediaViewRejectsAnAssetOwnedByAnotherTenant(): void
    {
        $this->grant(['mfg.media.view']);
        Services::injectMock('mediaLibraryRepository', new class {
            public function findById(int $id): ?array
            {
                // owner_id 999 — a different manufacturer entirely.
                return ['id' => $id, 'owner_type' => 'vendor', 'owner_id' => 999, 'object_key' => 'vendors/999/media/secret.pdf'];
            }
        });

        $r = $this->withSession($this->ownerSession())->get('manufacturer/media/8/view');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/media', (string) $r->getRedirectUrl());
        $this->assertStringNotContainsString('secret.pdf', (string) $r->getRedirectUrl());
    }

    public function testMediaUploadPresignIsDeniedWithoutUploadPermission(): void
    {
        $this->grant(['mfg.media.view']); // view but not upload

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/media/presign', $this->csrf() + ['filename' => 'x.png', 'content_type' => 'image/png', 'size' => '10']);

        $r->assertStatus(403);
    }

    public function testDocumentsRenderForPermittedUser(): void
    {
        $this->grant(['mfg.document.view', 'mfg.document.upload']);
        $this->ensureVendorDocumentTables();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/documents');

        $r->assertStatus(200);
        // The factory-licence type is manufacturer-specific; fssai (a food licence) is not offered.
        $this->assertStringContainsString('Factory Licence', (string) $r->getBody());
        $this->assertStringNotContainsString('Fssai', (string) $r->getBody());
    }

    public function testDocumentsAreDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']);

        $r = $this->withSession($this->ownerSession())->get('manufacturer/documents');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/dashboard', (string) $r->getRedirectUrl());
    }

    public function testDocumentPresignIsDeniedWithoutUploadPermission(): void
    {
        $this->grant(['mfg.document.view']);

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/documents/presign', $this->csrf() + ['filename' => 'gst.pdf', 'content_type' => 'application/pdf', 'size' => '100'])
            ->assertStatus(403);
    }

    /** The dummy file serve must refuse a key outside this tenant's own prefix. */
    public function testDocumentFileServeRejectsAForeignKey(): void
    {
        $this->grant(['mfg.document.view']);

        $r = $this->withSession($this->ownerSession())
            ->get('manufacturer/documents/file?key=' . rawurlencode('vendors/999/documents/other.pdf'));

        $r->assertStatus(403);
    }

    // ------------------------------------------------------------------------ staff

    /** @return object the injected staff-repository spy */
    private function mockStaffRepo(): object
    {
        $repo = new class {
            public array $created   = [];
            public array $updated   = [];
            public array $statusSet = [];
            public ?int $createReturns = 91;

            public function staffWithUnits(int $manufacturerId): array
            {
                return [[
                    'id' => 31, 'user_id' => 601, 'staff_type' => 'unit_manager',
                    'employee_code' => 'EMP-31', 'designation' => 'Shift lead', 'status' => 'active',
                    'name' => 'Ravi Kumar', 'email' => 'ravi@precision.example', 'phone' => '9800000001',
                    'units' => 'Bhiwandi Plant',
                ]];
            }

            public function findStaff(int $staffId, int $manufacturerId): ?array
            {
                return $staffId === 31
                    ? ['id' => 31, 'user_id' => 601, 'staff_type' => 'unit_manager', 'employee_code' => 'EMP-31', 'designation' => 'Shift lead', 'status' => 'active', 'name' => 'Ravi Kumar', 'email' => 'ravi@precision.example', 'phone' => '9800000001']
                    : null;
            }

            public function staffUnits(int $staffId): array { return [11]; }

            public function emailExists(string $email, ?int $exceptUserId = null): bool
            {
                return $email === 'taken@precision.example';
            }

            public function createStaff(int $manufacturerId, array $d, ?int $actorId = null): ?int
            {
                $this->created[] = $d;

                return $this->createReturns;
            }

            public function updateStaff(int $staffId, int $manufacturerId, array $d, ?int $actorId = null): bool
            {
                $this->updated[] = [$staffId, $d];

                return true;
            }

            public function setStatus(int $staffId, int $manufacturerId, string $status, ?int $actorId = null): bool
            {
                $this->statusSet[] = [$staffId, $status];

                return true;
            }
        };
        Services::injectMock('manufacturerStaffRepository', $repo);

        return $repo;
    }

    public function testStaffListRendersForOwner(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $this->mockStaffRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/staff');

        $r->assertStatus(200);
        $this->assertStringContainsString('Ravi Kumar', (string) $r->getBody());
        $this->assertStringContainsString('Bhiwandi Plant', (string) $r->getBody());
    }

    public function testStaffListIsDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']); // no mfg.staff.view
        $this->mockStaffRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/staff');

        $r->assertRedirect();
        $this->assertStringContainsString('manufacturer/dashboard', (string) $r->getRedirectUrl());
    }

    /**
     * Writing staff is the owner's job. Holding mfg.staff.manage without being the
     * owner is not enough, and without mfg.staff.request there is no propose path
     * either, so the write is refused outright rather than queued.
     */
    public function testStaffCreateIsRefusedForANonOwnerWithoutARequestPath(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $r = $this->withSession($this->postSession($this->staffSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'new@precision.example', 'staff_type' => 'store_keeper', 'mshop_ids' => [11],
        ]);

        $r->assertRedirect();
        $r->assertSessionHas('error', "You don't have permission to manage staff.");
        $this->assertSame([], $repo->created, 'unit staff must not be able to hire');
    }

    public function testStaffCreateStoresTheAssignment(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $r = $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'new@precision.example', 'staff_type' => 'store_keeper',
            'mshop_ids' => [11, 12], 'primary_unit' => 12,
        ]);

        $r->assertRedirect();
        $this->assertCount(1, $repo->created);
        $this->assertSame([11, 12], $repo->created[0]['mshop_ids']);
        $this->assertSame(12, $repo->created[0]['primary_unit']);
        $this->assertSame('store_keeper', $repo->created[0]['staff_type']);
    }

    /**
     * The tenant boundary. Unit 99 belongs to somebody else, so it must be dropped
     * before the repository ever sees it — otherwise an owner could assign their own
     * staff into another manufacturer's factory by editing the form post.
     */
    public function testStaffCreateDiscardsUnitsBelongingToAnotherManufacturer(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'new@precision.example', 'staff_type' => 'store_keeper',
            'mshop_ids' => [11, 99], 'primary_unit' => 99,
        ])->assertRedirect();

        $this->assertCount(1, $repo->created);
        $this->assertSame([11], $repo->created[0]['mshop_ids'], 'a foreign unit id must never reach the repository');
        // ...and the rejected id must not survive as the primary either.
        $this->assertSame(11, $repo->created[0]['primary_unit']);
    }

    /** With every posted unit foreign, there is nothing left to assign — reject outright. */
    public function testStaffCreateRejectsWhenEveryUnitIsForeign(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'new@precision.example', 'staff_type' => 'store_keeper',
            'mshop_ids' => [99],
        ])->assertRedirect();

        $this->assertSame([], $repo->created);
    }

    public function testStaffCreateRejectsAnUnknownRole(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'new@precision.example',
            'staff_type' => 'vendor_owner', 'mshop_ids' => [11],
        ])->assertRedirect();

        $this->assertSame([], $repo->created, 'a role outside the manufacturer set must not be assignable');
    }

    public function testStaffCreateRejectsADuplicateEmail(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))->post('manufacturer/staff', $this->csrf() + [
            'name' => 'New Hire', 'email' => 'taken@precision.example',
            'staff_type' => 'store_keeper', 'mshop_ids' => [11],
        ])->assertRedirect();

        $this->assertSame([], $repo->created);
    }

    public function testStaffSuspendWritesTheNewStatus(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $repo = $this->mockStaffRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/staff/31/suspend', $this->csrf() + ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertSame([[31, 'suspended']], $repo->statusSet);
    }

    public function testStaffEditFormRendersTheCurrentAssignment(): void
    {
        $this->grant(['mfg.staff.view', 'mfg.staff.manage']);
        $this->mockStaffRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/staff/31/edit');

        $r->assertStatus(200);
        $this->assertStringContainsString('Ravi Kumar', (string) $r->getBody());
        $this->assertStringContainsString('Bhiwandi Plant', (string) $r->getBody());
    }

    // ------------------------------------------------------------------ navigation

    /**
     * The new screens must actually be reachable from the nav. Building a page and
     * leaving it out of the sidebar is the failure mode this panel already had — the
     * purchase-intake screens on the vendor side were routed and built but had no nav
     * entry at all, so they were reachable only by typing the URL.
     */
    public function testOwnerSidebarLinksToTheNewScreens(): void
    {
        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/me')->getBody();

        $this->assertStringContainsString('manufacturer/profile', $body);
        $this->assertStringContainsString('manufacturer/notifications', $body);
    }

    /**
     * Unit staff must see which unit they are acting in. BaseManufacturerController had
     * exported activeMshopName/unitSwitch/activeMshopId since it was written, but the
     * topbar looked for the vendor panel's activeShopName/shopSwitch/activeShopId, so
     * nothing ever rendered.
     */
    public function testUnitStaffSeeTheirActiveUnitInTheTopbar(): void
    {
        $this->mockUserRepo();
        $body = (string) $this->withSession($this->staffSession())->get('manufacturer/me')->getBody();

        // Staff 502 is assigned to unit 11 only, so a chip rather than a switcher.
        $this->assertStringContainsString('Bhiwandi Plant', $body);
    }

    /** An owner is not pinned to one unit, so no chip. */
    public function testTheOwnerGetsNoActiveUnitChip(): void
    {
        $this->mockUserRepo();
        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/me')->getBody();

        $this->assertStringNotContainsString('text-bg-primary', $body, 'the owner works across all units');
    }

    /** The topbar's own two links resolve within this panel, never into the vendor one. */
    public function testTopbarLinksStayInTheManufacturerPanel(): void
    {
        $this->mockEmptyNotifications();
        $body = (string) $this->withSession($this->ownerSession())->get('manufacturer/notifications')->getBody();

        $this->assertStringContainsString('manufacturer/me', $body);
        $this->assertStringNotContainsString('vendor/notifications', $body);
        $this->assertStringNotContainsString('vendor/me', $body);
    }

    /**
     * Business Profile is owner-only in the sidebar too, not merely on the route.
     * A nav entry that 302s on click is a worse experience than no entry.
     */
    public function testUnitStaffSidebarHidesTheOwnerOnlyProfileEntry(): void
    {
        $this->mockUserRepo();
        $body = (string) $this->withSession($this->staffSession())->get('manufacturer/me')->getBody();

        $this->assertStringNotContainsString('manufacturer/profile', $body);
        // Staff is permission-gated rather than owner-gated, and setUp()'s grant does
        // not include mfg.staff.view — so it must be hidden here too. Owners bypass the
        // check entirely ($navIsOwner), which is exactly why this has to be asserted
        // from a STAFF session or the gate is untested.
        $this->assertStringNotContainsString('manufacturer/staff', $body);
        // ...while the per-user feed stays available to them.
        $this->assertStringContainsString('manufacturer/notifications', $body);
    }

    /** ...and appears once that permission is granted. */
    public function testUnitStaffSidebarShowsStaffWhenPermitted(): void
    {
        $this->grant(['mfg.notification.view', 'mfg.staff.view']);
        $this->mockUserRepo();
        $body = (string) $this->withSession($this->staffSession())->get('manufacturer/me')->getBody();

        $this->assertStringContainsString('manufacturer/staff', $body);
    }

    // --------------------------------------------------------------- notifications

    public function testNotificationsRender(): void
    {
        Services::injectMock('vendorNotificationRepository', new class {
            public function list(int $userId, int $limit = 50, int $offset = 0): array
            {
                // Column list copied from VendorNotificationRepository::list()'s own
                // select() — a mock shaped from memory would let the view read keys
                // the real query never returns.
                return [[
                    'id' => 3, 'event_code' => 'po.placed', 'title' => 'New purchase order',
                    'body' => 'PO-2026-0007 from Sole Mate Footwear', 'category' => 'transactional',
                    'data' => null, 'status' => 'sent', 'read_at' => null,
                    'created_at' => '2026-08-17 09:30:00',
                ]];
            }
        });

        $r = $this->withSession($this->ownerSession())->get('manufacturer/notifications');

        $r->assertStatus(200);
        $this->assertStringContainsString('New purchase order', (string) $r->getBody());
    }

    /** The feed is per-user, so a unit staff member gets their own, not the owner's. */
    public function testNotificationsAreScopedToTheSessionUser(): void
    {
        $seen = [];
        Services::injectMock('vendorNotificationRepository', new class ($seen) {
            public array $seen;
            public function __construct(array &$seen) { $this->seen = &$seen; }

            public function list(int $userId, int $limit = 50, int $offset = 0): array
            {
                $this->seen[] = $userId;

                return [];
            }
        });

        $this->withSession($this->staffSession())->get('manufacturer/notifications')->assertStatus(200);
        $this->assertSame([self::STAFF_UID], $seen);
    }

    // ------------------------------------------------------------------------- pos

    /**
     * The counter's repository, recording what unit id each call was given.
     *
     * The unit is the whole point of these tests: an invoice series and a stock balance
     * both belong to one unit, and a posted mshop_id must never reach a plant the
     * session cannot access. The sale itself is proved against real tables in
     * ManufacturerPosSaleTest — here the mock only reports which unit it was handed.
     */
    private function mockPosRepo(): object
    {
        $repo = new class {
            public array $searchedUnits = [];
            public array $soldUnits     = [];
            public array $receiptCalls  = [];

            public function search(int $m, int $unit, string $q, int $limit = 20): array
            {
                $this->searchedUnits[] = $unit;

                return [['variant_id' => 5, 'sku' => 'B-1', 'base_price' => '118.0000', 'title' => 'M8 Bolt', 'tax_rate' => '18.00', 'on_hand' => '100.000']];
            }

            public function resolveLines(int $m, array $cart, int $unit): array
            {
                return $cart === [] ? [] : [[
                    'variant_id' => 5, 'sku' => 'B-1', 'title' => 'M8 Bolt', 'hsn' => '7318',
                    'qty' => 1.0, 'unit_price' => 118.0, 'making_price' => 40.0,
                    'tax_rate' => 18.0, 'line_discount' => 0.0,
                ]];
            }

            public function createSale(array $ctx, array $lines, array $pay, array $opts = []): array
            {
                $this->soldUnits[] = (int) $ctx['mshop_id'];

                return ['ok' => true, 'sale_id' => 4, 'invoice_no' => 'BHW/2026-27/000001',
                    'grand_total' => 118.0, 'paid' => 200.0, 'change' => 82.0];
            }

            public function findForReceipt(int $saleId, int $m): ?array
            {
                $this->receiptCalls[] = [$saleId, $m];

                return ['id' => $saleId, 'invoice_no' => 'BHW/2026-27/000001', 'unit_name' => 'Bhiwandi Plant',
                    'unit_gstin' => '27AAACP1234F1Z5', 'address_json' => '{}', 'cashier_name' => 'Meera Iyer',
                    'customer_name' => null, 'sold_at' => '2026-08-18 11:00:00', 'taxable_value' => '100.0000',
                    'discount_total' => '0.0000', 'round_off' => '0.0000', 'grand_total' => '118.0000',
                    'items' => [['product_title_snapshot' => 'M8 Bolt', 'sku_snapshot' => 'B-1', 'qty' => '1.000', 'unit_price' => '118.0000', 'line_total' => '118.0000', 'tax_rate' => '18.00', 'taxable_value' => '100.0000', 'cgst' => '9.0000', 'sgst' => '9.0000']],
                    'payments' => [['tender_type' => 'cash', 'amount' => '200.0000']],
                    'tax_buckets' => [['rate' => 18.0, 'taxable' => 100.0, 'cgst' => 9.0, 'sgst' => 9.0]]];
            }

            public function recent(int $m, array $units, int $limit = 20): array
            {
                return [['id' => 4, 'invoice_no' => 'BHW/2026-27/000001', 'grand_total' => '118.0000', 'sold_at' => '2026-08-18 11:00:00', 'status' => 'completed', 'unit_name' => 'Bhiwandi Plant']];
            }
        };
        Services::injectMock('manufacturerPosSaleRepository', $repo);

        return $repo;
    }

    public function testCounterRenders(): void
    {
        $this->grant(['mfg.pos.view', 'mfg.pos.sell']);
        $this->mockPosRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/pos?mshop_id=11');

        $r->assertStatus(200);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('BHW/2026-27/000001', $body, 'recent sales belong on the counter');
        $this->assertStringNotContainsString('You can view the counter but not ring up sales', $body);
    }

    public function testCounterIsDeniedWithoutThePermission(): void
    {
        $this->grant(['mfg.product.view']);
        $this->mockPosRepo();

        $this->withSession($this->ownerSession())->get('manufacturer/pos?mshop_id=11')->assertRedirect();
    }

    /** View without sell is read-only, not a 403 — a supervisor may check the day's takings. */
    public function testViewWithoutSellRendersAReadOnlyCounter(): void
    {
        $this->grant(['mfg.pos.view']);
        $this->mockPosRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/pos?mshop_id=11');

        $r->assertStatus(200);
        $this->assertStringContainsString('You can view the counter but not ring up sales', (string) $r->getBody());
    }

    public function testRingingUpASaleRequiresTheSellPermission(): void
    {
        $this->grant(['mfg.pos.view']);
        $repo = $this->mockPosRepo();

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/pos/sale', $this->csrf() + ['mshop_id' => '11', 'items' => [['variant_id' => '5', 'qty' => '1']]]);

        $r->assertStatus(403);
        $this->assertSame([], $repo->soldUnits, 'nothing may be recorded');
    }

    public function testASaleIsRecordedAgainstThePostedUnit(): void
    {
        $this->grant(['mfg.pos.view', 'mfg.pos.sell']);
        $repo = $this->mockPosRepo();

        $r = $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/pos/sale', $this->csrf() + [
                'mshop_id' => '12', 'client_uuid' => 'web-1',
                'items'    => [['variant_id' => '5', 'qty' => '1']],
                'payments' => [['tender_type' => 'cash', 'amount' => '200']],
            ]);

        $r->assertStatus(200);
        $this->assertSame([12], $repo->soldUnits);
    }

    /**
     * A posted unit id must be intersected with the session's own units. Unit 13 is
     * another manufacturer's plant; accepting it would sell their stock on our invoice
     * series.
     */
    public function testAPostedUnitOutsideTheSessionIsRefused(): void
    {
        $this->grant(['mfg.pos.view', 'mfg.pos.sell']);
        $repo = $this->mockPosRepo();

        $this->withSession($this->postSession($this->ownerSession()))
            ->post('manufacturer/pos/sale', $this->csrf() + [
                'mshop_id' => '13',
                'items'    => [['variant_id' => '5', 'qty' => '1']],
                'payments' => [['tender_type' => 'cash', 'amount' => '200']],
            ]);

        $this->assertNotContains(13, $repo->soldUnits, "another manufacturer's unit must never be sold from");
    }

    /** Staff are pinned to their assigned unit — 11 — whatever they post. */
    public function testStaffCannotSellFromAUnitTheyAreNotAssignedTo(): void
    {
        $this->grant(['mfg.pos.view', 'mfg.pos.sell']);
        $repo = $this->mockPosRepo();

        $this->withSession($this->postSession($this->staffSession()))
            ->post('manufacturer/pos/sale', $this->csrf() + [
                'mshop_id' => '12',
                'items'    => [['variant_id' => '5', 'qty' => '1']],
                'payments' => [['tender_type' => 'cash', 'amount' => '200']],
            ]);

        $this->assertNotContains(12, $repo->soldUnits, 'unit 12 is not this staff member’s');
    }

    public function testCounterSearchIsScopedToTheSelectedUnit(): void
    {
        $this->grant(['mfg.pos.view', 'mfg.pos.sell']);
        $repo = $this->mockPosRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/pos/search?q=bolt&mshop_id=12');

        $r->assertStatus(200);
        $this->assertSame([12], $repo->searchedUnits);
        $this->assertStringContainsString('M8 Bolt', (string) $r->getBody());
    }

    public function testCounterSearchIsDeniedWithoutTheSellPermission(): void
    {
        $this->grant(['mfg.pos.view']);
        $this->mockPosRepo();

        $this->withSession($this->ownerSession())->get('manufacturer/pos/search?q=bolt&mshop_id=11')->assertStatus(403);
    }

    /** The receipt lookup must carry the session's manufacturer id, not just the sale id. */
    public function testTheReceiptIsLookedUpWithTheSessionsManufacturerId(): void
    {
        $this->grant(['mfg.pos.view']);
        $repo = $this->mockPosRepo();

        $r = $this->withSession($this->ownerSession())->get('manufacturer/pos/receipt/4');

        $r->assertStatus(200);
        $this->assertSame([[4, 1]], $repo->receiptCalls);
        $body = (string) $r->getBody();
        $this->assertStringContainsString('BHW/2026-27/000001', $body);
        $this->assertStringNotContainsString('you saved', strtolower($body), 'mrp is unused here — a savings line would print the whole sale as a discount');
    }
}
