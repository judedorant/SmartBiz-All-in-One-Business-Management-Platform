<?php

namespace App\Services;

use App\Jobs\RunAutomation;
use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationStep;
use App\Models\AutomationWorkflow;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomationService
{
    public function process(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $event = AutomationEvent::lockForUpdate()->findOrFail($id);
            if ($event->processed_at) {
                return;
            }
            $workflows = AutomationWorkflow::whereIn('id', $event->context['workflow_ids'] ?? [])->where('is_active', true)->where('trigger_type', $event->trigger_type)->where('created_at', '<=', $event->occurred_at)->get();
            foreach ($workflows as $workflow) {
                $condition = $workflow->configuration['lead_status'] ?? null;
                if ($condition && ($event->context['status'] ?? null) !== $condition) {
                    continue;
                }
                $log = new AutomationLog;
                $log->workflow_id = $workflow->id;
                $log->event_id = $event->id;
                $log->workflow_version = $workflow->version;
                $log->execution_status = 'pending';
                $log->execution_data = ['trigger' => $event->trigger_type, 'subject_type' => $event->subject_type, 'subject_id' => $event->subject_id];
                $log->save();
                foreach ($workflow->configuration['actions'] as $index => $action) {
                    $step = new AutomationStep;
                    $step->log_id = $log->id;
                    $step->action_index = $index;
                    $step->action_type = $action['type'];
                    $step->configuration = $action;
                    $step->save();
                }
                $this->queueRun($log->id);
            }
            $event->processed_at = now();
            $event->save();
        }, 5);
    }

    public function queueRun(int $id): void
    {
        DB::afterCommit(function () use ($id): void {
            try {
                RunAutomation::dispatch($id);
            } catch (\Throwable) {
                Log::warning('Automation run dispatch deferred', ['log_id' => $id]);
            }
        });
    }

    public function source(AutomationEvent $event, User $actor): ?Model
    {
        $class = match ($event->subject_type) {
            'customer' => Customer::class,'lead' => Lead::class,'appointment' => Appointment::class,'task' => Task::class,default => null
        };
        if (! $class) {
            return null;
        }$query = $class::visibleTo($actor);
        if ($event->subject_type === 'appointment' && $event->trigger_type === 'appointment_updated') {
            $query->withTrashed();
        }

        return $query->find($event->subject_id);
    }

    public function context(AutomationEvent $event, ?Model $source): array
    {
        return ['depth' => (int) ($event->context['depth'] ?? 0), 'id' => $event->subject_id, 'title' => $source instanceof Customer ? trim($source->first_name.' '.$source->last_name) : ($source?->title ?? 'Webhook event'), 'status' => $event->context['status'] ?? ($source instanceof Lead ? $source->stage?->slug : ($source?->status ?? '')), 'trigger' => $event->trigger_type];
    }

    public function audit(User $actor, string $action, int $id): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => $action.':'.$id, 'created_at' => now()]);
    }
}
