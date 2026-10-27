<?php

namespace App\Console\Commands;

use App\Events\AutomationTriggered;
use App\Jobs\ProcessAutomationEvent;
use App\Jobs\RunAutomation;
use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanAutomations extends Command
{
    protected $signature = 'smartbiz:automations';

    protected $description = 'Capture scheduled triggers and reconcile pending automation outbox work';

    public function handle(): int
    {
        if (AutomationWorkflow::where('is_active', true)->where('trigger_type', 'appointment_approaching')->exists()) {
            Appointment::whereIn('status', ['scheduled', 'confirmed'])->whereBetween('start_at', [now(), now()->addMinutes(30)])->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    AutomationTriggered::dispatch('appointment_approaching', 'appointment', $record->id, $record->version);
                }
            });
        }
        if (AutomationWorkflow::where('is_active', true)->where('trigger_type', 'task_overdue')->exists()) {
            Task::whereNull('automation_log_id')->whereIn('status', ['pending', 'in_progress'])->where('due_date', '<', now())->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    AutomationTriggered::dispatch('task_overdue', 'task', $record->id, $record->version);
                }
            });
        }
        AutomationEvent::whereNull('processed_at')->where('created_at', '<', now()->subMinute())->chunkById(100, function ($events): void {
            foreach ($events as $event) {
                ProcessAutomationEvent::dispatch($event->id);
            }
        });
        AutomationLog::whereIn('execution_status', ['pending', 'running'])->where('updated_at', '<', now()->subMinutes(5))->chunkById(100, function ($logs): void {
            foreach ($logs as $log) {
                RunAutomation::dispatch($log->id);
            }
        });
        DB::table('oauth_states')->where('expires_at', '<', now()->subDay())->delete();
        $this->info('Automation scan completed.');

        return self::SUCCESS;
    }
}
