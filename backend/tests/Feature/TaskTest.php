<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;

class TaskTest extends CrmTestCase
{
    private function payload(User $owner, array $changes = []): array
    {
        return array_replace(['title' => 'Follow up', 'description' => 'Contact customer', 'assigned_to' => $owner->id, 'priority' => 'high', 'status' => 'pending', 'due_date' => null, 'customer_id' => null, 'lead_id' => null], $changes);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Staff');

        return $user;
    }

    public function test_guests_and_roleless_users_cannot_access_tasks(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->postJson('/api/v1/tasks', [])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/tasks')->assertForbidden();
    }

    public function test_manager_crud_is_versioned_audited_and_notifies_assignee(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $payload = $this->payload($staff);
        $id = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.assignee.email')->json('data.id');
        $this->assertDatabaseCount('notifications', 1);
        $this->putJson('/api/v1/tasks/'.$id, [...$payload, 'title' => 'Call back', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/tasks/'.$id, [...$payload, 'version' => 1])->assertConflict();
        $this->assertDatabaseCount('notifications', 2);
        $this->putJson('/api/v1/tasks/'.$id, [...$payload, 'title' => 'Call back', 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->deleteJson('/api/v1/tasks/'.$id, ['version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/tasks/'.$id, ['version' => 2])->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $id]);
        $this->getJson('/api/v1/tasks/'.$id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $manager->id, 'action' => 'tasks.create:'.$id]);
    }

    public function test_staff_can_only_update_own_tasks_and_cannot_change_assignment_links_or_archive(): void
    {
        $staff = $this->signIn('Staff');
        $own = Task::factory()->for($staff, 'assignee')->create();
        $other = Task::factory()->create();
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/tasks/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/tasks/'.$other->id.'/status', ['status' => 'completed', 'version' => 1])->assertNotFound();
        $this->postJson('/api/v1/tasks', [])->assertForbidden();
        $this->getJson('/api/v1/tasks/staff')->assertForbidden();
        $payload = $this->payload($staff);
        unset($payload['assigned_to'],$payload['customer_id'],$payload['lead_id']);
        foreach (['assigned_to' => $staff->id, 'customer_id' => 1, 'lead_id' => 1, 'created_by' => $staff->id, 'completed_at' => '2026-01-01T00:00:00Z'] as $key => $value) {
            $this->putJson('/api/v1/tasks/'.$own->id, [...$payload, 'version' => 1, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->putJson('/api/v1/tasks/'.$own->id, [...$payload, 'version' => 1])->assertOk();
        $this->deleteJson('/api/v1/tasks/'.$own->id, ['version' => 2])->assertForbidden();
    }

    public function test_completion_dates_are_server_owned_preserved_and_cleared_on_reopening(): void
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $payload = $this->payload($manager, ['status' => 'completed']);
        $id = $this->postJson('/api/v1/tasks', $payload)->assertCreated()->json('data.id');
        $completed = Task::findOrFail($id)->completed_at->toISOString();
        $this->travel(1)->hours();
        $this->putJson('/api/v1/tasks/'.$id, [...$payload, 'title' => 'Amended', 'version' => 1])->assertOk()->assertJsonPath('data.completed_at', $completed);
        $this->patchJson('/api/v1/tasks/'.$id.'/status', ['version' => 2, 'status' => 'pending'])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->patchJson('/api/v1/tasks/'.$id.'/status', ['version' => 3, 'status' => 'completed'])->assertOk()->assertJsonPath('data.version', 4);
        $this->assertNotSame($completed, Task::findOrFail($id)->completed_at->toISOString());
    }

    public function test_filters_priority_order_null_due_dates_and_overdue_use_database_values(): void
    {
        $staff = $this->signIn('Staff');
        $this->travelTo('2026-10-26 12:00:00');
        $urgent = Task::factory()->for($staff, 'assignee')->create(['title' => 'Visible urgent', 'priority' => 'urgent', 'due_date' => now()->subMinute()]);
        Task::factory()->for($staff, 'assignee')->create(['title' => 'No due date', 'priority' => 'low', 'due_date' => null]);
        Task::factory()->for($staff, 'assignee')->create(['status' => 'completed', 'due_date' => now()->subDay()]);
        Task::factory()->create(['title' => 'Hidden urgent', 'priority' => 'urgent', 'due_date' => now()->subDay()]);
        $this->getJson('/api/v1/tasks?overdue=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $urgent->id)->assertJsonPath('data.0.is_overdue', true);
        $this->getJson('/api/v1/tasks?sort=priority&direction=desc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $urgent->id)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/tasks?search=Hidden')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/tasks?sort=title%3BDROP')->assertUnprocessable();
        $this->getJson('/api/v1/tasks?status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/tasks?assigned_to='.$urgent->assigned_to.'&priority=urgent')->assertJsonPath('meta.total', 1);
    }

    public function test_linked_customer_is_inferred_but_inconsistent_links_rejected_and_hidden_leads_are_redacted(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->create();
        $this->postJson('/api/v1/tasks', $this->payload($staff, ['customer_id' => $other->id, 'lead_id' => $lead->id]))->assertUnprocessable()->assertJsonValidationErrors('lead_id');
        $id = $this->postJson('/api/v1/tasks', $this->payload($staff, ['lead_id' => $lead->id]))->assertCreated()->assertJsonPath('data.customer_id', $customer->id)->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/tasks/'.$id)->assertOk()->assertJsonPath('data.lead', null)->assertJsonPath('data.customer.id', $customer->id);
        $this->getJson('/api/v1/leads/'.$lead->id)->assertNotFound();
        $this->getJson('/api/v1/customers/'.$customer->id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($manager, 'web');
        $this->deleteJson('/api/v1/tasks/'.$id, ['version' => 1])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/customers/'.$customer->id)->assertNotFound();
    }

    public function test_validation_and_invalid_assignees_do_not_persist_tasks_or_notifications(): void
    {
        $manager = $this->signIn();
        $roleless = User::factory()->create();
        $inactive = $this->staff();
        $inactive->update(['status' => 'inactive']);
        foreach ([$roleless, $inactive] as $owner) {
            $this->postJson('/api/v1/tasks', $this->payload($owner))->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        }
        $this->postJson('/api/v1/tasks', $this->payload($manager, ['title' => '', 'status' => 'unknown', 'priority' => 'unknown', 'due_date' => '2026-10-26 12:00']))->assertUnprocessable()->assertJsonValidationErrors(['title', 'status', 'priority', 'due_date']);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_inactive_unchanged_owner_and_archived_links_do_not_prevent_unrelated_edits(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $customer = Customer::factory()->create();
        $task = Task::factory()->for($staff, 'assignee')->for($customer)->create();
        $staff->update(['status' => 'inactive']);
        $customer->delete();
        $payload = $this->payload($staff);
        unset($payload['assigned_to'],$payload['customer_id'],$payload['lead_id']);
        $this->putJson('/api/v1/tasks/'.$task->id, [...$payload, 'version' => 1])->assertOk()->assertJsonPath('data.customer', null);
    }

    public function test_task_mutations_update_authorized_dashboard_metrics_and_completion_series(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $this->travelTo('2026-10-26 12:00:00');
        $task = $this->postJson('/api/v1/tasks', $this->payload($staff, ['due_date' => '2026-10-25T12:00:00Z']))->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/dashboard/summary?preset=today&timezone=UTC')->assertOk()->assertJsonPath('data.metrics.pending_tasks', 1)->assertJsonPath('data.metrics.overdue_tasks', 1);
        $this->patchJson('/api/v1/tasks/'.$task.'/status', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->getJson('/api/v1/dashboard/summary?preset=today&timezone=UTC')->assertOk()->assertJsonPath('data.metrics.pending_tasks', 0)->assertJsonPath('data.metrics.overdue_tasks', 0);
        $this->getJson('/api/v1/dashboard/analytics?preset=today&timezone=UTC')->assertOk()->assertJsonPath('data.task_completion.0.completed', 1);
    }

    #[TestWith(['Admin', true])] #[TestWith(['Manager', true])] #[TestWith(['Staff', false])]
    public function test_policy_matrix(string $role, bool $manage): void
    {
        $user = $this->signIn($role);
        $own = Task::factory()->for($user, 'assignee')->create();
        $other = Task::factory()->create();
        $this->assertTrue(Gate::forUser($user)->allows('update', $own));
        $this->assertSame($manage, Gate::forUser($user)->allows('view', $other));
        $this->assertSame($manage, Gate::forUser($user)->allows('create', Task::class));
        $this->assertSame($manage, Gate::forUser($user)->allows('delete', $own));
    }
}
