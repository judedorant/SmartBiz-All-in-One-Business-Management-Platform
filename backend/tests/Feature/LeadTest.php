<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;

class LeadTest extends CrmTestCase
{
    public function test_anonymous_lead_requests_return_401(): void
    {
        $this->getJson('/api/v1/leads')->assertUnauthorized();
        $this->getJson('/api/v1/pipeline-stages')->assertUnauthorized();
    }

    public function test_manager_creates_assigned_lead_with_exact_decimal_and_activity(): void
    {
        $manager = $this->signIn();
        $staff = User::factory()->create();
        $customer = Customer::factory()->create();
        $response = $this->postJson('/api/v1/leads', $this->leadPayload($staff, ['customer_id' => $customer->id]))->assertCreated()->assertJsonPath('data.estimated_value', '12500.25')->assertJsonPath('data.status', 'new');
        $this->assertDatabaseHas('leads', ['id' => $response->json('data.id'), 'assigned_to' => $staff->id, 'created_by' => $manager->id]);
        $this->assertDatabaseCount('lead_activities', 1);
    }

    public function test_staff_can_update_own_lead_but_cannot_reassign_create_delete_or_convert(): void
    {
        $staff = $this->signIn('Staff');
        $lead = Lead::factory()->for($staff, 'assignee')->create();
        $other = User::factory()->create();
        $this->putJson('/api/v1/leads/'.$lead->id, ['title' => 'Follow up', 'source' => 'Website', 'estimated_value' => '200.10', 'version' => 1, 'notes' => 'New details'])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'title' => 'Follow up', 'notes' => 'New details']);
        $this->putJson('/api/v1/leads/'.$lead->id, ['title' => 'Follow up', 'source' => 'Website', 'estimated_value' => '200.10', 'version' => 2, 'assigned_to' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->postJson('/api/v1/leads', $this->leadPayload($staff))->assertForbidden();
        $this->deleteJson('/api/v1/leads/'.$lead->id, ['version' => 2])->assertForbidden();
        $this->postJson('/api/v1/leads/'.$lead->id.'/convert', [...$this->customerPayload(), 'version' => 2])->assertForbidden();
    }

    public function test_staff_cannot_read_update_move_or_record_activity_on_another_lead(): void
    {
        $staff = $this->signIn('Staff');
        $other = Lead::factory()->create();
        $won = PipelineStage::where('slug', 'won')->firstOrFail();
        $this->getJson('/api/v1/leads/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/leads/'.$other->id.'/activities')->assertNotFound();
        $this->patchJson('/api/v1/leads/'.$other->id.'/stage', ['pipeline_stage_id' => $won->id, 'version' => 1])->assertNotFound();
        $this->putJson('/api/v1/leads/'.$other->id, ['title' => 'X', 'source' => 'Test', 'estimated_value' => '1', 'version' => 1])->assertNotFound();
        $this->postJson('/api/v1/leads/'.$other->id.'/activities', ['body' => 'Unauthorized'])->assertNotFound();
        $this->getJson('/api/v1/leads?search='.$other->source)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_stage_changes_allow_won_lost_reopening_and_detect_stale_versions(): void
    {
        $staff = $this->signIn('Staff');
        $lead = Lead::factory()->for($staff, 'assignee')->create();
        $path = '/api/v1/leads/'.$lead->id.'/stage';
        $won = PipelineStage::where('slug', 'won')->firstOrFail();
        $lost = PipelineStage::where('slug', 'lost')->firstOrFail();
        $this->patchJson($path, ['pipeline_stage_id' => $won->id, 'version' => 1])->assertOk()->assertJsonPath('data.status', 'won')->assertJsonPath('data.version', 2);
        $this->patchJson($path, ['pipeline_stage_id' => $lost->id, 'version' => 1])->assertConflict();
        $this->patchJson($path, ['pipeline_stage_id' => $lost->id, 'version' => 2])->assertOk()->assertJsonPath('data.status', 'lost');
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'activity_type' => 'stage_changed', 'description' => 'Stage changed from New to Won.']);
        $this->assertDatabaseCount('lead_activities', 2);
    }

    public function test_conversion_is_atomic_preserves_assignment_and_rejects_repeated_conversion(): void
    {
        $manager = $this->signIn();
        $staff = User::factory()->create();
        $lead = Lead::factory()->for($staff, 'assignee')->create();
        $response = $this->postJson('/api/v1/leads/'.$lead->id.'/convert', [...$this->customerPayload(), 'version' => 1])->assertCreated()->assertJsonPath('data.version', 2);
        $customerId = $response->json('data.customer_id');
        $this->assertDatabaseHas('customer_user', ['customer_id' => $customerId, 'user_id' => $staff->id]);
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'created_by' => $manager->id]);
        $this->assertNotNull($lead->fresh()->converted_at);
        $this->postJson('/api/v1/leads/'.$lead->id.'/convert', [...$this->customerPayload(['email' => null]), 'version' => 2])->assertConflict();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_activities', 1);
        $this->assertDatabaseCount('lead_activities', 1);
    }

    public function test_conversion_validation_and_stale_version_do_not_create_partial_customers(): void
    {
        $this->signIn();
        $lead = Lead::factory()->create(['version' => 2]);
        $this->postJson('/api/v1/leads/'.$lead->id.'/convert', [...$this->customerPayload(), 'version' => 1])->assertConflict();
        $this->postJson('/api/v1/leads/'.$lead->id.'/convert', ['version' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('lead_activities', 0);
        $this->assertNull($lead->fresh()->customer_id);
    }

    public function test_already_linked_lead_cannot_be_converted_and_converted_link_cannot_be_changed(): void
    {
        $manager = $this->signIn();
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->create(['converted_at' => now()]);
        $this->postJson('/api/v1/leads/'.$lead->id.'/convert', [...$this->customerPayload(), 'version' => 1])->assertConflict();
        $this->putJson('/api/v1/leads/'.$lead->id, [...$this->leadPayload($manager), 'version' => 1])->assertConflict();
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
    }

    public function test_lead_activity_is_recorded_for_assigned_staff(): void
    {
        $staff = $this->signIn('Staff');
        $lead = Lead::factory()->for($staff, 'assignee')->create();
        $this->postJson('/api/v1/leads/'.$lead->id.'/activities', ['body' => 'Customer requested a callback.'])->assertCreated();
        $this->getJson('/api/v1/leads/'.$lead->id.'/activities')->assertOk()->assertJsonPath('data.0.description', 'Customer requested a callback.')->assertJsonPath('data.0.user.id', $staff->id)->assertJsonMissingPath('data.0.user.email');
    }

    public function test_pipeline_filter_pagination_and_counts_are_scoped(): void
    {
        $staff = $this->signIn('Staff');
        $won = PipelineStage::where('slug', 'won')->firstOrFail();
        Lead::factory()->count(2)->for($staff, 'assignee')->create(['pipeline_stage_id' => $won->id]);
        Lead::factory()->create(['pipeline_stage_id' => $won->id]);
        $this->getJson('/api/v1/leads?pipeline_stage_id='.$won->id.'&per_page=1&sort=estimated_value&direction=asc')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pipeline-stages')->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('data.0.slug', 'new')->assertJsonPath('data.6.slug', 'lost');
    }

    public function test_invalid_value_assignment_customer_stage_and_sort_are_rejected(): void
    {
        $manager = $this->signIn();
        $archived = Customer::factory()->create();
        $archived->delete();
        $this->postJson('/api/v1/leads', $this->leadPayload($manager, ['estimated_value' => '12.345', 'customer_id' => $archived->id]))->assertUnprocessable()->assertJsonValidationErrors(['estimated_value', 'customer_id']);
        $this->postJson('/api/v1/leads', $this->leadPayload($manager, ['estimated_value' => '-1', 'assigned_to' => 999999, 'status' => 'won', 'created_by' => 1]))->assertUnprocessable()->assertJsonValidationErrors(['estimated_value', 'assigned_to', 'status', 'created_by']);
        $lead = Lead::factory()->create();
        $this->patchJson('/api/v1/leads/'.$lead->id.'/stage', ['pipeline_stage_id' => 999999, 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('pipeline_stage_id');
        $this->getJson('/api/v1/leads?direction=desc%3BDROP')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson('/api/v1/leads?per_page=1000')->assertUnprocessable();
    }

    public function test_manager_can_reassign_and_soft_delete_leads_with_audit_history(): void
    {
        $manager = $this->signIn();
        $lead = Lead::factory()->create();
        $this->putJson('/api/v1/leads/'.$lead->id, [...$this->leadPayload($manager), 'version' => 1])->assertOk();
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'activity_type' => 'assigned']);
        $this->deleteJson('/api/v1/leads/'.$lead->id, ['version' => 2])->assertOk();
        $this->assertSoftDeleted($lead);
        $this->getJson('/api/v1/leads/'.$lead->id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'leads.delete:'.$lead->id]);
    }

    #[TestWith(['Admin', true, true])]
    #[TestWith(['Manager', true, true])]
    #[TestWith(['Staff', false, false])]
    public function test_crm_policy_matrix(string $role, bool $creates, bool $deletes): void
    {
        $user = $this->signIn($role);
        $own = Lead::factory()->for($user, 'assignee')->create();
        $other = Lead::factory()->create();
        $customer = Customer::factory()->create();
        $this->assertSame($creates, Gate::forUser($user)->allows('create', Lead::class));
        $this->assertSame($deletes, Gate::forUser($user)->allows('delete', $own));
        $this->assertTrue(Gate::forUser($user)->allows('update', $own));
        $this->assertSame($creates, Gate::forUser($user)->allows('update', $other));
        $this->assertSame($creates, Gate::forUser($user)->allows('view', $customer));
        $this->assertSame($creates, Gate::forUser($user)->allows('create', Customer::class));
    }

    public function test_assignee_metadata_is_restricted_and_has_no_private_fields(): void
    {
        $manager = $this->signIn();
        $this->getJson('/api/v1/crm/assignees')->assertOk()->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.password');
        $staff = User::factory()->create();
        $staff->assignRole('Staff');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/v1/crm/assignees')->assertForbidden();
    }
}
