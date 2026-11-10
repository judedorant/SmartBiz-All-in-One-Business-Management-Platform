<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;

class UserManagementTest extends CrmTestCase
{
    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Taylor Reed', 'email' => 'taylor@example.test', 'role' => 'Staff', 'status' => 'active', 'password' => 'Test-Password-123!', 'password_confirmation' => 'Test-Password-123!'], $changes);
    }

    private function updatePayload(User $user, array $changes = []): array
    {
        return array_replace(['name' => $user->name, 'email' => $user->email, 'role' => $user->getRoleNames()->first(), 'status' => $user->status, 'revision' => $user->managementRevision()], $changes);
    }

    public function test_anonymous_requests_cannot_manage_users(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/users', $this->payload())->assertUnauthorized();
        $this->putJson('/api/v1/users/1', [])->assertUnauthorized();
    }

    #[TestWith(['Manager'])]
    #[TestWith(['Staff'])]
    public function test_non_admins_cannot_list_create_or_edit_accounts(string $role): void
    {
        $actor = $this->signIn($role);
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', $this->payload(['role' => 'Admin']))->assertForbidden();
        $this->putJson('/api/v1/users/'.$actor->id, $this->updatePayload($actor, ['role' => 'Admin']))->assertForbidden();
        $this->assertTrue($actor->fresh()->hasRole($role));
    }

    #[TestWith(['Admin'])]
    #[TestWith(['Manager'])]
    #[TestWith(['Staff'])]
    public function test_admin_creates_accounts_with_one_role_and_hashed_passwords(string $role): void
    {
        $admin = $this->signIn('Admin');
        $response = $this->postJson('/api/v1/users', $this->payload(['role' => $role, 'email' => ' TAYLOR@example.test ']))
            ->assertCreated()->assertJsonPath('data.roles', [$role])->assertJsonPath('data.email', 'taylor@example.test')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $user = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('Test-Password-123!', $user->password));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'users.created:'.$user->id]);
    }

    public function test_list_is_searchable_filtered_and_paginated_without_credentials(): void
    {
        $this->signIn('Admin');
        foreach (['active', 'inactive'] as $status) {
            User::factory()->create(['name' => 'Taylor '.$status, 'status' => $status])->assignRole('Staff');
        }
        User::factory()->create(['name' => 'Taylor Manager'])->assignRole('Manager');
        $this->getJson('/api/v1/users?search=Taylor&role=Staff&status=inactive&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Taylor inactive')->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/users?role=Unknown&per_page=500')->assertUnprocessable();
    }

    public function test_admin_updates_profile_and_role_and_revokes_existing_access(): void
    {
        $this->signIn('Admin');
        $target = User::factory()->create();
        $target->assignRole('Manager');
        $password = $target->password;
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target, ['name' => 'Updated Name', 'role' => 'Staff']))
            ->assertOk()->assertJsonPath('data.roles', ['Staff'])->assertJsonPath('data.name', 'Updated Name');
        $this->assertSame($password, $target->fresh()->password);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertFalse($target->fresh()->can('users.manage'));
    }

    public function test_password_reset_changes_hash_revokes_sessions_and_keeps_secrets_out_of_audit(): void
    {
        $admin = $this->signIn('Admin');
        $target = User::factory()->create();
        $target->assignRole('Staff');
        DB::table('sessions')->insert(['id' => 'reset-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target, ['password' => 'New-Password-987!', 'password_confirmation' => 'New-Password-987!']))
            ->assertOk()->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('New-Password-987!', $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'reset-session']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'users.password_reset:'.$target->id]);
    }

    public function test_deactivation_blocks_access_and_reactivation_restores_status(): void
    {
        $admin = $this->signIn('Admin');
        $target = User::factory()->create();
        $target->assignRole('Staff');
        $this->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target, ['status' => 'inactive']))->assertOk();
        $this->assertSame('inactive', $target->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->actingAs($target->fresh(), 'web')->getJson('/api/v1/me')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target->fresh(), ['status' => 'active']))->assertOk();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_admin_resetting_own_password_is_logged_out(): void
    {
        $admin = $this->signIn('Admin');
        $this->putJson('/api/v1/users/'.$admin->id, $this->updatePayload($admin, ['password' => 'New-Password-987!', 'password_confirmation' => 'New-Password-987!']))->assertOk();
        $this->assertGuest('web');
        $this->assertTrue(Hash::check('New-Password-987!', $admin->fresh()->password));
    }

    public function test_admin_cannot_remove_own_admin_role_or_deactivate_self(): void
    {
        $admin = $this->signIn('Admin');
        $this->putJson('/api/v1/users/'.$admin->id, $this->updatePayload($admin, ['role' => 'Staff']))->assertUnprocessable();
        $this->putJson('/api/v1/users/'.$admin->id, $this->updatePayload($admin, ['status' => 'inactive']))->assertUnprocessable();
        $this->assertTrue($admin->fresh()->hasRole('Admin'));
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_last_active_admin_is_protected_even_from_direct_permission_holders(): void
    {
        $target = $this->signIn('Admin');
        $actor = User::factory()->create();
        $actor->givePermissionTo('users.manage');
        $this->actingAs($actor, 'web')->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target, ['role' => 'Staff']))->assertUnprocessable();
        $this->assertTrue($target->fresh()->hasRole('Admin'));
    }

    public function test_other_admin_can_be_demoted_when_an_active_admin_remains(): void
    {
        $this->signIn('Admin');
        $target = User::factory()->create();
        $target->assignRole('Admin');
        $this->putJson('/api/v1/users/'.$target->id, $this->updatePayload($target, ['role' => 'Manager']))->assertOk()->assertJsonPath('data.roles', ['Manager']);
    }

    public function test_stale_updates_are_rejected_without_overwriting_newer_edits(): void
    {
        $this->signIn('Admin');
        $target = User::factory()->create();
        $target->assignRole('Staff');
        $payload = $this->updatePayload($target, ['name' => 'Stale overwrite']);
        $target->update(['name' => 'Latest name']);
        $this->putJson('/api/v1/users/'.$target->id, $payload)->assertConflict();
        $this->assertSame('Latest name', $target->fresh()->name);
    }

    #[TestWith([['role' => 'Owner'], 'role'])]
    #[TestWith([['status' => 'deleted'], 'status'])]
    #[TestWith([['password' => 'weak', 'password_confirmation' => 'weak'], 'password'])]
    #[TestWith([['password_confirmation' => 'Wrong-Password-123!'], 'password'])]
    #[TestWith([['permissions' => ['users.manage']], 'permissions'])]
    public function test_invalid_accounts_and_permission_injection_are_rejected(array $changes, string $field): void
    {
        $this->signIn('Admin');
        $this->postJson('/api/v1/users', $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('users', ['email' => 'taylor@example.test']);
    }

    public function test_duplicate_email_and_missing_update_revision_are_rejected(): void
    {
        $admin = $this->signIn('Admin');
        $this->postJson('/api/v1/users', $this->payload(['email' => $admin->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $payload = $this->updatePayload($admin);
        unset($payload['revision']);
        $this->putJson('/api/v1/users/'.$admin->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('revision');
    }
}
