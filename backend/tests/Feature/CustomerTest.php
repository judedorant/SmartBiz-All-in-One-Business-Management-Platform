<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestWith;

class CustomerTest extends CrmTestCase
{
    public function test_anonymous_requests_receive_401(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', $this->customerPayload())->assertUnauthorized();
    }

    #[TestWith(['Admin'])]
    #[TestWith(['Manager'])]
    public function test_manager_can_create_edit_view_and_soft_delete_customer(string $role): void
    {
        $actor = $this->signIn($role);
        $staff = User::factory()->create();
        $staff->assignRole('Staff');
        $data = $this->customerPayload(['assigned_user_ids' => [$staff->id], 'email' => ' ADA@EXAMPLE.TEST ']);
        $response = $this->postJson('/api/v1/customers', $data)->assertCreated()->assertJsonPath('data.email', 'ada@example.test')->assertJsonPath('data.assignees.0.id', $staff->id);
        $id = $response->json('data.id');
        $code = $response->json('data.customer_code');
        $this->assertStringStartsWith('CUS-', $code);
        $this->assertDatabaseHas('customers', ['id' => $id, 'created_by' => $actor->id]);
        $this->getJson('/api/v1/customers/'.$id)->assertOk()->assertJsonMissingPath('data.creator.password');
        $this->putJson('/api/v1/customers/'.$id, [...$data, 'company' => 'Updated company', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('customers', ['id' => $id, 'company' => 'Updated company']);
        $this->getJson('/api/v1/customers/'.$id.'/activities')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson('/api/v1/customers/'.$id, ['version' => 2])->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $id]);
        $this->getJson('/api/v1/customers/'.$id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actor->id, 'action' => 'customers.delete:'.$id]);
    }

    public function test_staff_only_sees_explicit_or_assigned_lead_customers_even_with_search(): void
    {
        $staff = $this->signIn('Staff');
        $explicit = Customer::factory()->create();
        $explicit->assignees()->attach($staff);
        $linked = Customer::factory()->create();
        Lead::factory()->for($linked)->for($staff, 'assignee')->create();
        $hidden = Customer::factory()->create(['first_name' => 'Secret']);
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/customers?search=Secret')->assertOk()->assertJsonCount(0, 'data');
        foreach (['', '/notes', '/activities'] as $suffix) {
            $this->getJson('/api/v1/customers/'.$hidden->id.$suffix)->assertNotFound();
        }
        $this->getJson('/api/v1/customers/'.$explicit->id)->assertOk();
        $this->getJson('/api/v1/customers/'.$linked->id)->assertOk();
    }

    public function test_staff_cannot_create_edit_delete_or_add_customer_notes(): void
    {
        $staff = $this->signIn('Staff');
        $customer = Customer::factory()->create();
        $customer->assignees()->attach($staff);
        $this->postJson('/api/v1/customers', $this->customerPayload())->assertForbidden();
        $this->putJson('/api/v1/customers/'.$customer->id, [...$this->customerPayload(), 'version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/customers/'.$customer->id, ['version' => 1])->assertForbidden();
        $this->postJson('/api/v1/customers/'.$customer->id.'/notes', ['body' => 'Forbidden'])->assertForbidden();
        $this->assertDatabaseCount('customer_notes', 0);
    }

    public function test_notes_are_scoped_to_their_customer_and_changes_are_recorded(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $path = '/api/v1/customers/'.$customer->id.'/notes';
        $note = $this->postJson($path, ['body' => 'Important context'])->assertCreated()->json('data.id');
        $this->putJson($path.'/'.$note, ['body' => 'Updated context'])->assertOk()->assertJsonPath('data.body', 'Updated context');
        $this->deleteJson('/api/v1/customers/'.$other->id.'/notes/'.$note)->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($path.'/'.$note)->assertOk();
        $this->assertDatabaseCount('customer_notes', 0);
        $this->assertDatabaseCount('customer_activities', 3);
    }

    public function test_customer_stale_version_returns_409_without_overwriting_data(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['version' => 2]);
        $this->putJson('/api/v1/customers/'.$customer->id, [...$this->customerPayload(), 'version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/customers/'.$customer->id, ['version' => 1])->assertConflict();
        $this->assertSame(2, $customer->fresh()->version);
        $this->assertDatabaseCount('customer_activities', 0);
    }

    public function test_email_uniqueness_includes_archived_customers_and_normalizes_case(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['email' => 'ada@example.test']);
        $customer->delete();
        $this->postJson('/api/v1/customers', $this->customerPayload(['email' => 'ADA@example.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_missing_fields_inactive_assignments_and_mass_assignment_are_rejected(): void
    {
        $this->signIn();
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->postJson('/api/v1/customers', [])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'status', 'assigned_user_ids']);
        $this->postJson('/api/v1/customers', $this->customerPayload(['assigned_user_ids' => [$inactive->id]]))->assertUnprocessable()->assertJsonValidationErrors('assigned_user_ids.0');
        $this->postJson('/api/v1/customers', $this->customerPayload(['created_by' => 999, 'customer_code' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors(['created_by', 'customer_code']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_search_filter_sort_and_pagination_are_database_driven(): void
    {
        $this->signIn();
        Customer::factory()->create(['first_name' => 'Beta', 'company' => 'Target', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'Alpha', 'company' => 'Target', 'status' => 'active']);
        Customer::factory()->create(['company' => 'Target', 'status' => 'inactive']);
        $this->getJson('/api/v1/customers?search=Target&status=active&sort=first_name&direction=asc&per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.first_name', 'Alpha');
        $this->getJson('/api/v1/customers?sort=last_name%20desc%3Bdrop')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/customers?sort=title')->assertUnprocessable();
    }

    public function test_staff_customer_access_is_revoked_after_lead_reassignment(): void
    {
        $staff = $this->signIn('Staff');
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->for($staff, 'assignee')->create();
        $this->getJson('/api/v1/customers/'.$customer->id)->assertOk();
        $lead->update(['assigned_to' => User::factory()->create()->id]);
        $this->getJson('/api/v1/customers/'.$customer->id)->assertNotFound();
    }

    public function test_roleless_account_cannot_list_customer_records(): void
    {
        $this->signIn();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/customers')->assertForbidden();
    }
}
