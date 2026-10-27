<?php

namespace App\Listeners;

use App\Events\AutomationTriggered;
use App\Jobs\ProcessAutomationEvent;
use App\Models\AutomationEvent;
use App\Models\AutomationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaptureAutomationEvent
{
    public function handle(AutomationTriggered $event): void
    {
        $workflowIds = AutomationWorkflow::where('trigger_type', $event->trigger)->where('is_active', true)->pluck('id')->all();
        if (! $workflowIds) {
            return;
        }
        $record = AutomationEvent::firstOrCreate(['event_key' => $event->trigger.':'.$event->subjectType.':'.$event->subjectId.':'.$event->version], ['trigger_type' => $event->trigger, 'subject_type' => $event->subjectType, 'subject_id' => $event->subjectId, 'subject_version' => $event->version, 'context' => [...$event->context, 'workflow_ids' => $workflowIds], 'occurred_at' => now()]);
        if ($record->wasRecentlyCreated) {
            DB::afterCommit(function () use ($record): void {
                try {
                    ProcessAutomationEvent::dispatch($record->id);
                } catch (\Throwable) {
                    Log::warning('Automation outbox dispatch deferred', ['event_id' => $record->id]);
                }
            });
        }
    }
}
