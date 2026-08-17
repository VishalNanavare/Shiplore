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
        service('superglobals')->setServer('HTTP_HOST', 'manufacturer.shiplore.in');
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

    /** `notifications` is not in MinimalSchema, so any page reaching it needs this. */
    private function mockEmptyNotifications(): void
    {
        Services::injectMock('vendorNotificationRepository', new class {
            public function list(int $userId, int $limit = 50, int $offset = 0): array { return []; }
        });
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
        // ...while the per-user feed stays available to them.
        $this->assertStringContainsString('manufacturer/notifications', $body);
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
}
