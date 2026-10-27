<?php

namespace Tests\Feature;

use App\Mail\AutomationMail;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\AutomationNotice;
use App\Services\AutomationRunner;
use App\Services\AutomationService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

class AutomationExecutionTest extends CrmTestCase
{
    private function execution(array $actions): AutomationLog
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'is_active' => true, 'configuration' => ['actions' => $actions]]);
        Lead::factory()->create(['assigned_to' => $admin->id]);
        $event = AutomationEvent::firstOrFail();
        app(AutomationService::class)->process($event->id);

        return AutomationLog::firstOrFail();
    }

    public function test_completed_steps_are_not_repeated_when_later_action_runs(): void
    {
        $log = $this->execution([['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high', 'due_in_hours' => 1], ['type' => 'send_notification']]);
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('pending', $log->fresh()->execution_status);
        app(AutomationRunner::class)->run($log->id);
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('succeeded', $log->fresh()->execution_status);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_mail_action_uses_plain_text_and_fixed_template_substitution(): void
    {
        Mail::fake();
        $log = $this->execution([['type' => 'send_email', 'recipient' => 'fixed', 'to' => 'recipient@example.test', 'transport' => 'mail', 'subject' => 'Lead {{id}}', 'body' => 'New lead: {{title}}']]);
        app(AutomationRunner::class)->run($log->id);
        Mail::assertSent(AutomationMail::class, fn ($mail) => $mail->hasTo('recipient@example.test') && str_contains($mail->mailSubject, 'Lead '));
        $this->assertSame('succeeded', $log->fresh()->execution_status);
        app(AutomationRunner::class)->run($log->id);
        Mail::assertSentCount(1);
    }

    public function test_unknown_external_outcomes_are_not_automatically_repeated(): void
    {
        $log = $this->execution([['type' => 'send_email', 'recipient' => 'fixed', 'to' => 'recipient@example.test', 'transport' => 'mail', 'subject' => 'Lead', 'body' => 'Hello']]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('provider secret must not be logged'));
        app(AutomationRunner::class)->run($log->id);
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('uncertain', $log->fresh()->execution_status);
        $this->assertSame('mail_outcome_unknown', $log->fresh()->error_message);
        $this->postJson('/api/v1/automations/'.$log->workflow_id.'/logs/'.$log->id.'/retry')->assertConflict();
    }

    public function test_expired_worker_lease_marks_in_flight_external_step_uncertain(): void
    {
        $log = $this->execution([['type' => 'send_notification']]);
        $log->execution_status = 'running';
        $log->lease_until = now()->subMinute();
        $log->lease_token = 'abandoned';
        $log->save();
        $log->steps()->update(['status' => 'processing']);
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('uncertain', $log->fresh()->execution_status);
        $this->assertSame('worker_interrupted', $log->fresh()->error_message);
    }

    public function test_live_worker_lease_prevents_duplicate_claims(): void
    {
        $log = $this->execution([['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high', 'due_in_hours' => 1]]);
        $log->lease_until = now()->addMinute();
        $log->save();
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_workflow_disable_and_creator_deactivation_are_rechecked_before_actions(): void
    {
        $log = $this->execution([['type' => 'send_notification']]);
        $log->workflow->update(['is_active' => false]);
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('skipped', $log->fresh()->execution_status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_safe_provider_retry_resumes_without_repeating_prior_task_creation(): void
    {
        $log = $this->execution([['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high', 'due_in_hours' => 1], ['type' => 'google_sheets_row', 'integration_account_id' => 1, 'spreadsheet_id' => 'spreadsheet123', 'range' => 'Sheet1!A:G']]);
        $account = IntegrationAccount::factory()->create(['user_id' => $log->workflow->created_by]);
        $step = $log->steps()->where('action_index', 1)->firstOrFail();
        $step->configuration = [...$step->configuration, 'integration_account_id' => $account->id];
        $step->save();
        Http::preventStrayRequests();
        Http::fake(['sheets.googleapis.com/*' => Http::sequence()->push([], 429)->push([], 200)]);
        app(AutomationRunner::class)->run($log->id);
        try {
            app(AutomationRunner::class)->run($log->id);
        } catch (\RuntimeException $error) {
            $this->assertSame('Automation provider temporarily unavailable.', $error->getMessage());
        }$this->assertSame('failed', $log->fresh()->execution_status);
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('succeeded', $log->fresh()->execution_status);
        $this->assertSame(2, $step->fresh()->attempts);
        Http::assertSentCount(2);
    }

    public function test_local_partial_failure_rolls_back_task_and_notification_effects(): void
    {
        $log = $this->execution([['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high', 'due_in_hours' => 1]]);
        $this->mock(TaskService::class, function ($mock) use ($log): void {
            $mock->shouldReceive('store')->once()->andReturnUsing(function () use ($log): void {
                Task::factory()->create();
                $log->workflow->creator->notify(new AutomationNotice($log->id));
                throw new \RuntimeException('rejected');
            });
        });
        app(AutomationRunner::class)->run($log->id);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('failed', $log->fresh()->execution_status);
    }

    public function test_deactivated_creator_cannot_execute_previously_queued_actions(): void
    {
        $log = $this->execution([['type' => 'send_notification']]);
        $log->workflow->creator->update(['status' => 'inactive']);
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('skipped', $log->fresh()->execution_status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_known_retryable_failure_can_be_requeued_but_completed_steps_are_retained(): void
    {
        $log = $this->execution([['type' => 'send_notification']]);
        $log->execution_status = 'failed';
        $log->save();
        $step = $log->steps()->firstOrFail();
        $step->status = 'failed';
        $step->retryable = true;
        $step->attempts = 3;
        $step->save();
        $this->postJson('/api/v1/automations/'.$log->workflow_id.'/logs/'.$log->id.'/retry')->assertOk();
        $this->assertSame('pending', $step->fresh()->status);
        $this->assertSame(0, $step->fresh()->attempts);
        app(AutomationRunner::class)->run($log->id);
        $this->assertSame('succeeded',$log->fresh()->execution_status);
    }
}
