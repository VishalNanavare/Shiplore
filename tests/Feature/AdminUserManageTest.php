<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin staff-user management: create, edit, role assignment and the privilege guards.
 *
 * The guards exist to stop an admin locking the platform out of itself: removing or
 * suspending the last Super Admin leaves nobody able to manage roles, with no route
 * back through the UI.
 */
final class AdminUserManageTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private object $repo;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTP_HOST', 'admin.shiplore.in');
        Services::resetSingle('request');
        Services::resetSingle('routes');
        Services::resetSingle('router');

        $this->repo = new class {
            public array $created = [];
            public array $roleSet = [];
            public array $status  = [];
            public array $profile = [];
            public ?int $createReturns = 99;
            public bool $actorIsSuper  = true;
            public bool $targetIsSuper = false;
            public int $superAdmins    = 2;
            public ?array $user = ['id' => 5, 'name' => 'Staff', 'email' => 's@x.com', 'status' => 'active', 'role_id' => 3];

            public function list(): array { return []; }
            public function find(int $id): ?array { return $this->user; }
            public function emailExists(string $e): bool { return false; }
            public function platformRoles(bool $includeSuperAdmin = false): array
            {
                $r = [['id' => 3, 'name' => 'Ops', 'code' => 'ops_manager']];
                if ($includeSuperAdmin) { $r[] = ['id' => 1, 'name' => 'Super Admin', 'code' => 'super_admin']; }
                return $r;
            }
            public function isSuperAdminRole(int $roleId): bool { return $roleId === 1; }
            public function activeSuperAdminCount(): int { return $this->superAdmins; }
            public function hasSuperAdmin(int $userId): bool
            {
                // user 7 is the acting admin in these tests; others follow targetIsSuper
                return $userId === 7 ? $this->actorIsSuper : $this->targetIsSuper;
            }
            public bool $phoneTaken = false;
            public function phoneExists(?string $e164): bool { return $this->phoneTaken; }
            public function create(array $d, ?int $a = null): ?int { $this->created[] = $d; return $this->createReturns; }
            public function setRole(int $u, int $r, ?int $a = null): bool { $this->roleSet[] = [$u, $r]; return true; }
            public function setStatus(int $id, string $s, ?int $a = null): bool { $this->status[] = [$id, $s]; return true; }
            public bool $profileReturns = true;
            public function updateProfile(int $id, string $n, string $e, ?string $p = null, ?int $actor = null): bool
            {
                $this->profile[] = [$id, $n, $e, $p, $actor];
                return $this->profileReturns;
            }
            public function updatePassword(int $id, string $h): void {}
        };
        Services::injectMock('adminUserRepository', $this->repo);
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
            public function loadAssignments(int $userId): array
            {
                return [['permissions' => $this->perms, 'scope_type' => 'platform', 'scope_id' => null, 'attributes' => []]];
            }
        });
    }

    /** Acting admin is user 7. */
    private function sess(): array
    {
        return service('session')->get() + ['isLoggedIn' => true, 'user_id' => 7, 'user_name' => 'Admin', 'principal_type' => 'platform'];
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    // ------------------------------------------------------------------ create

    /** A Super Admin can see and grant the Super Admin role. */
    public function testSuperAdminCanCreateAnotherSuperAdmin(): void
    {
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'New', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '1'];
        $this->withSession($this->sess())->post('admin/users/store', $data)->assertRedirect();

        $this->assertCount(1, $this->repo->created);
        $this->assertSame(1, $this->repo->created[0]['role_id']);
    }

    /** A non-super admin must not be able to mint a Super Admin. */
    public function testNonSuperAdminCannotGrantSuperAdmin(): void
    {
        $this->repo->actorIsSuper = false;
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'New', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '1'];
        $this->withSession($this->sess())->post('admin/users/store', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->created, 'super_admin must not be grantable by a non-super admin');
    }

    /** A roleless staff account can log in but do nothing — refuse it. */
    public function testRoleIsRequired(): void
    {
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'New', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '0'];
        $this->withSession($this->sess())->post('admin/users/store', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->created);
    }

    /** A failed create must not report success. */
    public function testFailedCreateIsReported(): void
    {
        $this->repo->createReturns = null;
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'New', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '3'];
        $r = $this->withSession($this->sess())->post('admin/users/store', $data);

        $r->assertRedirect();
        $this->assertNotEmpty(session('error'), 'a rolled-back create must surface an error, not "Staff user created."');
    }

    // -------------------------------------------------------------------- edit

    public function testEditFormRenders(): void
    {
        $this->grant(['user.update']);
        $r = $this->withSession($this->sess())->get('admin/users/5/edit');

        $r->assertStatus(200);
        $this->assertStringContainsString('Save changes', (string) $r->getBody());
    }

    public function testUpdateSavesProfileAndRole(): void
    {
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'Renamed', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertSame([[5, 'Renamed', 'r@x.com', '', 7]], $this->repo->profile);
        $this->assertSame([[5, 3]], $this->repo->roleSet);
    }

    /** A blank password on edit means "keep the current one", not "set an empty one". */
    public function testShortPasswordOnEditIsRejected(): void
    {
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => 'short', 'role_id' => '3'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->profile, 'a too-short password must abort the whole update');
    }

    public function testEditRequiresUpdatePermission(): void
    {
        $this->grant(['user.view']);
        $this->withSession($this->sess())->get('admin/users/5/edit')->assertRedirect();
    }

    // ------------------------------------------------------------------ phone

    /** The mobile must be editable — it was previously hidden on the edit form. */
    public function testEditFormShowsMobile(): void
    {
        $this->repo->user = ['id' => 5, 'name' => 'Staff', 'email' => 's@x.com', 'status' => 'active', 'role_id' => 3, 'phone' => '+919812345678', 'phone_verified_at' => null];
        $this->grant(['user.update']);

        $body = (string) $this->withSession($this->sess())->get('admin/users/5/edit')->getBody();

        $this->assertStringContainsString('name="phone"', $body, 'the edit form must expose the mobile field');
        $this->assertStringContainsString('+919812345678', $body, 'and prefill the current number');
        $this->assertStringContainsString('Mobile not verified', $body);
    }

    public function testEditFormShowsVerifiedBadge(): void
    {
        $this->repo->user = ['id' => 5, 'name' => 'Staff', 'email' => 's@x.com', 'status' => 'active', 'role_id' => 3, 'phone' => '+919812345678', 'phone_verified_at' => '2026-07-01 10:00:00'];
        $this->grant(['user.update']);

        $body = (string) $this->withSession($this->sess())->get('admin/users/5/edit')->getBody();
        $this->assertStringContainsString('Mobile verified', $body);
    }

    /** The phone reaches the repository on update so it can actually be saved. */
    public function testUpdatePassesPhoneThrough(): void
    {
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3', 'phone' => '9812345678'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertSame('9812345678', $this->repo->profile[0][3]);
        $this->assertSame(7, $this->repo->profile[0][4], 'updated_by must be the acting admin, not the edited user');
    }

    /** An unparseable mobile is refused with a message that names the mobile. */
    public function testInvalidMobileRejectedOnUpdate(): void
    {
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3', 'phone' => '12345'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->profile, 'a bad mobile must abort before any write');
    }

    public function testInvalidMobileRejectedOnCreate(): void
    {
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'N', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '3', 'phone' => 'abc'];
        $this->withSession($this->sess())->post('admin/users/store', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->created);
    }

    /** uq_users_phone spans all principal types — a taken number must be caught up front. */
    public function testDuplicateMobileRejectedOnCreate(): void
    {
        $this->repo->phoneTaken = true;
        $this->grant(['user.create']);
        $data = $this->csrf() + ['name' => 'N', 'email' => 'n@x.com', 'password' => 'secret123', 'role_id' => '3', 'phone' => '9812345678'];
        $this->withSession($this->sess())->post('admin/users/store', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->created, 'a phone already in users must not reach the INSERT');
    }

    /** A clash reported by updateProfile must surface, not be swallowed. */
    public function testProfileClashOnUpdateIsReported(): void
    {
        $this->repo->profileReturns = false;
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3', 'phone' => '9812345678'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertNotEmpty(session('error'));
        $this->assertCount(0, $this->repo->roleSet, 'a failed profile save must not still apply the role');
    }

    // ------------------------------------------------------- lock-out guards

    /** Demoting the last Super Admin would leave nobody able to manage roles. */
    public function testCannotRemoveRoleFromLastSuperAdmin(): void
    {
        $this->repo->targetIsSuper = true;
        $this->repo->superAdmins   = 1;
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertCount(0, $this->repo->roleSet, 'the last Super Admin must not be demotable');
    }

    /** With a spare Super Admin the same demotion is allowed. */
    public function testCanRemoveSuperAdminWhenAnotherExists(): void
    {
        $this->repo->targetIsSuper = true;
        $this->repo->superAdmins   = 2;
        $this->grant(['user.update']);
        $data = $this->csrf() + ['name' => 'X', 'email' => 'r@x.com', 'password' => '', 'role_id' => '3'];
        $this->withSession($this->sess())->post('admin/users/5/update', $data)->assertRedirect();

        $this->assertSame([[5, 3]], $this->repo->roleSet);
    }

    public function testCannotSuspendLastSuperAdmin(): void
    {
        $this->repo->targetIsSuper = true;
        $this->repo->superAdmins   = 1;
        $this->grant(['user.suspend']);
        $this->withSession($this->sess())->post('admin/users/5/suspend', $this->csrf())->assertRedirect();

        $this->assertCount(0, $this->repo->status, 'the last active Super Admin must not be suspendable');
    }

    /** Pre-existing guard, kept under test: no self status change. */
    public function testCannotSuspendSelf(): void
    {
        $this->repo->user = ['id' => 7, 'name' => 'Admin', 'email' => 'a@x.com', 'status' => 'active', 'role_id' => 1];
        $this->grant(['user.suspend']);
        $this->withSession($this->sess())->post('admin/users/7/suspend', $this->csrf())->assertRedirect();

        $this->assertCount(0, $this->repo->status);
    }

    /** The index must not offer a Suspend button on the acting admin's own row. */
    public function testIndexHidesSuspendOnOwnRow(): void
    {
        $this->grant(['user.view']);
        Services::injectMock('adminUserRepository', new class {
            public function list(): array
            {
                return [
                    ['id' => 7, 'name' => 'Me',    'email' => 'a@x.com', 'status' => 'active', 'roles' => 'Super Admin'],
                    ['id' => 5, 'name' => 'Other', 'email' => 'b@x.com', 'status' => 'active', 'roles' => 'Ops'],
                ];
            }
        });

        $body = (string) $this->withSession($this->sess())->get('admin/users')->getBody();

        $this->assertStringContainsString('admin/users/5/suspend', $body, 'other users keep the Suspend action');
        $this->assertStringNotContainsString('admin/users/7/suspend', $body, 'own row must not offer Suspend');
        $this->assertStringContainsString('This is you', $body);
    }
}
