<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CrmTestCase extends TestCase
{
    use RefreshDatabase;

    protected function signIn(string $role = 'Manager'): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, PipelineStageSeeder::class]);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        return $user;
    }

    protected function customerPayload(array $changes = []): array
    {
        return array_replace(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => null, 'company' => 'Analytical Works', 'address' => null, 'status' => 'active', 'assigned_user_ids' => []], $changes);
    }

    protected function leadPayload(User $owner, array $changes = []): array
    {
        return array_replace(['title' => 'Operations project', 'source' => 'Referral', 'estimated_value' => '12500.25', 'assigned_to' => $owner->id, 'customer_id' => null, 'expected_close_date' => '2027-01-15', 'notes' => 'Discuss scope.'], $changes);
    }
}
