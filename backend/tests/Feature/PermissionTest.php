<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['admin' => ['Admin', true, true], 'manager' => ['Manager', true, false], 'staff' => ['Staff', false, false]];
    }

    #[DataProvider('roles')]
    public function test_permission_and_policy_matrix(string $role, bool $assignsTasks, bool $managesUsers): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        $other = User::factory()->create();

        $this->assertTrue($user->can('dashboard.view'));
        $this->assertSame($assignsTasks, $user->can('tasks.assign'));
        $this->assertSame($managesUsers, Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $user));
        $this->assertSame($managesUsers, Gate::forUser($user)->allows('view', $other));
    }

    #[DataProvider('roles')]
    public function test_endpoints_enforce_role_permissions(string $role, bool $assignsTasks, bool $managesUsers): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/dashboard/access')->assertOk();
        $this->getJson('/api/v1/team/access')->assertStatus($assignsTasks ? 200 : 403);
        $this->getJson('/api/v1/admin/access')->assertStatus($managesUsers ? 200 : 403);
    }

    public function test_authenticated_account_without_role_cannot_access_workspace_abilities(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/dashboard/access')->assertForbidden()->assertJsonPath('success', false);
    }

    public function test_role_seeding_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('permissions', 16);
    }
}
