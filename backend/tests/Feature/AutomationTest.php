<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Services\AutomationRunner;
use App\Services\AutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;

class AutomationTest extends CrmTestCase
{
    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Lead follow-up', 'trigger_type' => 'lead_created', 'is_active' => true, 'configuration' => ['actions' => [['type' => 'create_task', 'title' => 'Follow up {{title}}', 'priority' => 'high', 'due_in_hours' => 24]]]], $changes);
    }

    public function test_guests_cannot_manage_automations(): void
    {
        $this->getJson('/api/v1/automations')->assertUnauthorized();
        $this->postJson('/api/v1/automations', [])->assertUnauthorized();
    }

    #[TestWith(['Staff'])] #[TestWith(['Manager'])]
    public function test_non_admins_cannot_manage_automation_integrations_or_webhooks(string $role): void
    {
        $this->signIn($role);
        foreach (['/automations', '/automations/catalog', '/integrations', '/webhooks'] as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden();
        }$this->postJson('/api/v1/automations', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/webhooks', [])->assertForbidden();
    }

    public function test_workflows_can_be_created_updated_disabled_archived_with_version_and_audit(): void
    {
        $admin = $this->signIn('Admin');
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/automations', $payload)->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        $this->getJson('/api/v1/automations?search=follow&is_active=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson('/api/v1/automations/'.$id, [...$payload, 'version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_active', false);
        $this->putJson('/api/v1/automations/'.$id, [...$payload, 'version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/automations/'.$id, ['version' => 2])->assertOk();
        $this->assertSoftDeleted('automation_workflows', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'automations.create:'.$id]);
    }

    public function test_configuration_rejects_arbitrary_actions_unknown_settings_and_executable_templates(): void
    {
        $this->signIn('Admin');
        foreach ([['type' => 'shell', 'command' => 'id'], ['type' => 'create_task', 'title' => '{{unknown}}', 'priority' => 'high', 'due_in_hours' => 24], ['type' => 'send_notification', 'sql' => 'DROP TABLE users']] as $action) {
            $this->postJson('/api/v1/automations', $this->payload(['configuration' => ['actions' => [$action]]]))->assertUnprocessable();
        }
        $this->getJson('/api/v1/automations?sort=name;DROP')->assertUnprocessable();
        $this->assertDatabaseCount('automation_workflows', 0);
    }

    public function test_lead_creation_captures_durable_event_and_runs_local_actions_once(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        $this->postJson('/api/v1/automations', $this->payload())->assertCreated();
        $id = $this->postJson('/api/v1/leads', $this->leadPayload($admin))->assertCreated()->json('data.id');
        $event = AutomationEvent::firstOrFail();
        $this->assertSame($id, $event->subject_id);
        app(AutomationService::class)->process($event->id);
        app(AutomationService::class)->process($event->id);
        $this->assertDatabaseCount('automation_logs', 1);
        $log = AutomationLog::firstOrFail();
        app(AutomationRunner::class)->run($log->id);
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 1);
        $task = Task::firstOrFail();
        $this->assertSame('Follow up Operations project', $task->title);
        $this->assertSame($admin->id, $task->assigned_to);
        $this->assertSame('succeeded', $log->fresh()->execution_status);
        $this->getJson('/api/v1/automations/'.$log->workflow_id.'/logs')->assertOk()->assertJsonPath('data.0.steps.0.status', 'succeeded')->assertJsonMissingPath('data.0.steps.0.configuration');
    }

    public function test_outbox_rolls_back_with_business_write(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true]);
        try {
            DB::transaction(function (): void {
                Lead::factory()->create();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('automation_events', 0);
    }

    public function test_disabled_or_archived_source_and_revoked_creator_prevent_execution(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        $workflow = AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true]);
        $lead = Lead::factory()->create(['assigned_to' => $admin->id]);
        $event = AutomationEvent::firstOrFail();
        app(AutomationService::class)->process($event->id);
        $log = AutomationLog::firstOrFail();
        $lead->delete();
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('skipped', $log->fresh()->execution_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_scheduler_deduplicates_overdue_and_approaching_events_and_skips_resolved_tasks(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'task_overdue']);
        $task = Task::factory()->for($admin, 'assignee')->create(['due_date' => now()->subHour()]);
        $this->artisan('smartbiz:automations')->assertSuccessful();
        $this->artisan('smartbiz:automations')->assertSuccessful();
        $this->assertDatabaseCount('automation_events', 1);
        $event = AutomationEvent::firstOrFail();
        app(AutomationService::class)->process($event->id);
        $task->update(['status' => 'completed']);
        $log = AutomationLog::firstOrFail();
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('skipped', $log->fresh()->execution_status);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_missing_google_configuration_is_reported_without_exposing_secrets(): void
    {
        $this->signIn('Admin');
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('data.google_configured', false)->assertJsonMissingPath('data.client_secret');
        $this->postJson('/api/v1/integrations/google/google_gmail/connect')->assertUnprocessable();
    }

    public function test_stage_trigger_filters_events_and_does_not_retroactively_include_new_workflows(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        $workflow = AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'lead_status_changed', 'configuration' => ['lead_status' => 'qualified', 'actions' => [['type' => 'send_notification']]]]);
        $lead = Lead::factory()->create(['assigned_to' => $admin->id]);
        $stage = PipelineStage::where('slug', 'qualified')->firstOrFail();
        $this->patchJson('/api/v1/leads/'.$lead->id.'/stage', ['pipeline_stage_id' => $stage->id, 'version' => 1])->assertOk();
        $event = AutomationEvent::firstOrFail();
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'lead_status_changed']);
        app(AutomationService::class)->process($event->id);
        $this->assertDatabaseCount('automation_logs', 1);
        $this->assertSame($workflow->id, AutomationLog::firstOrFail()->workflow_id);
    }

    public function test_customer_and_appointment_creation_capture_their_allowlisted_triggers(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        foreach (['customer_created', 'appointment_created'] as $trigger) {
            AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => $trigger]);
        }
        $customer = Customer::factory()->create();
        Appointment::factory()->for($customer)->create();
        $this->assertSame(['customer_created', 'appointment_created'], AutomationEvent::orderBy('id')->pluck('trigger_type')->all());
    }

    public function test_appointment_approaching_scan_is_deduplicated_and_ignores_cancelled_bookings(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'appointment_approaching']);
        Appointment::factory()->create(['start_at' => now()->addMinutes(15), 'end_at' => now()->addMinutes(45)]);
        Appointment::factory()->create(['start_at' => now()->addMinutes(15), 'status' => 'cancelled']);
        $this->artisan('smartbiz:automations')->assertSuccessful();
        $this->artisan('smartbiz:automations')->assertSuccessful();
        $this->assertDatabaseCount('automation_events', 1);
    }

    public function test_active_toggle_does_not_require_reentering_invalidated_external_settings(): void
    {
        $admin = $this->signIn('Admin');
        $workflow = AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true]);
        $this->patchJson('/api/v1/automations/'.$workflow->id.'/active', ['version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/automations/'.$workflow->id.'/active', ['version' => 1, 'is_active' => true])->assertConflict();
    }

    public function test_appointment_changes_and_archival_emit_calendar_sync_events(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'appointment_updated']);
        $appointment = Appointment::factory()->create();
        $appointment->version = 2;
        $appointment->status = 'cancelled';
        $appointment->save();
        $appointment->version = 3;
        $appointment->save();
        $appointment->delete();
        $this->assertSame([2, 3], AutomationEvent::orderBy('id')->pluck('subject_version')->all());
        $this->assertTrue(AutomationEvent::latest('id')->firstOrFail()->context['archived']);
    }

    public function test_automation_generated_tasks_do_not_start_recursive_overdue_chains(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'trigger_type' => 'task_overdue', 'configuration' => ['actions' => [['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high', 'due_in_hours' => 0]]]]);
        Task::factory()->for($admin, 'assignee')->create(['due_date' => now()->subHour()]);
        $this->artisan('smartbiz:automations')->assertSuccessful();
        app(AutomationService::class)->process(AutomationEvent::firstOrFail()->id);
        $log = AutomationLog::firstOrFail();
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertSame(1, Task::whereNotNull('automation_log_id')->count());
        $this->travel(2)->minutes();
        $this->artisan('smartbiz:automations')->assertSuccessful();
        $this->assertDatabaseCount('automation_events', 1);
    }
}
