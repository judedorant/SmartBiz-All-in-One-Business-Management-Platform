<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationStep;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomationRunner
{
    public function __construct(private AutomationService $events, private AutomationActionService $actions) {}

    public function run(int $id): void
    {
        $claim = DB::transaction(function () use ($id): ?array {
            $log = AutomationLog::with(['workflow.creator', 'event'])->lockForUpdate()->findOrFail($id);
            if (in_array($log->execution_status, ['succeeded', 'skipped', 'uncertain'], true) || ($log->lease_until && $log->lease_until->isFuture())) {
                return null;
            }
            $workflow = $log->workflow;
            $actor = $workflow->creator;
            $event = $log->event;
            if ($workflow->trashed() || ! $workflow->is_active || ! $actor || $actor->status !== 'active' || ! $actor->can('automations.manage')) {
                $this->stop($log, 'skipped', 'workflow_unavailable');

                return null;
            }
            $source = $this->events->source($event, $actor);
            if (! $this->sourceIsCurrent($event, $source)) {
                $this->stop($log, 'skipped', 'source_unavailable_or_stale');

                return null;
            }
            if ($log->steps()->where('status', 'processing')->exists()) {
                $log->steps()->where('status', 'processing')->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
                $this->stop($log, 'uncertain', 'worker_interrupted');

                return null;
            }
            $step = $log->steps()->where('status', '!=', 'succeeded')->orderBy('action_index')->lockForUpdate()->first();
            if (! $step) {
                $this->stop($log, 'succeeded', null);

                return null;
            }
            if ($source instanceof Appointment && $source->trashed() && $step->action_type !== 'google_calendar_event') {
                $this->stop($log, 'skipped', 'source_archived');

                return null;
            }
            if ($step->status === 'uncertain' || ($step->status === 'failed' && (! $step->retryable || $step->attempts >= 3))) {
                $this->stop($log, $step->status, $step->error_code);

                return null;
            }
            $step->status = 'processing';
            $step->attempts++;
            $step->save();
            $log->execution_status = 'running';
            $log->lease_until = now()->addMinutes(2);
            $log->lease_token = (string) Str::uuid();
            $log->save();
            $context = $this->events->context($event, $source);
            if ($this->actions->local($step->action_type)) {
                try {
                    DB::transaction(function () use ($step, $event, $actor, $source, $context, $log): void {
                        $this->actions->execute($step, $event, $actor, $source, $context);
                        $this->finish($log, $step);
                    }, 1);
                } catch (\Throwable) {
                    $step->status = 'failed';
                    $step->retryable = false;
                    $step->error_code = 'local_action_rejected';
                    $step->save();
                    $this->stop($log, 'failed', 'local_action_rejected');
                }

                return null;
            }

            return [$log, $step, $event, $actor, $source, $context];
        }, 5);
        if (! $claim) {
            return;
        }
        [$log,$step,$event,$actor,$source,$context] = $claim;
        $failure = null;
        try {
            $this->actions->execute($step, $event, $actor, $source, $context);
        } catch (ActionFailure $error) {
            $failure = $error;
        } catch (\Throwable) {
            $failure = new ActionFailure('external_outcome_unknown', false, true);
        }
        DB::transaction(function () use ($log, $step, $failure): void {
            $current = AutomationLog::lockForUpdate()->findOrFail($log->id);
            if ($current->lease_token !== $log->lease_token) {
                return;
            }
            if (! $failure) {
                $this->finish($current, $step);

                return;
            }
            $step->status = $failure->uncertain ? 'uncertain' : 'failed';
            $step->retryable = $failure->retryable;
            $step->error_code = $failure->errorCode;
            $step->save();
            $this->stop($current, $step->status, $failure->errorCode);
        }, 5);
        if ($failure?->retryable && $step->attempts < 3) {
            throw new \RuntimeException('Automation provider temporarily unavailable.');
        }
    }

    private function sourceIsCurrent(AutomationEvent $event, ?Model $source): bool
    {
        if ($event->subject_type !== 'webhook' && ! $source) {
            return false;
        }

        return match ($event->trigger_type) {
            'appointment_approaching' => $source instanceof Appointment && in_array($source->status, ['scheduled', 'confirmed'], true) && ! $source->start_at->isPast() && $source->start_at->lte(now()->addMinutes(30)),
            'task_overdue' => $source instanceof Task && $source->automation_log_id === null && in_array($source->status, ['pending', 'in_progress'], true) && $source->due_date?->isPast(),
            'lead_status_changed' => $source instanceof Lead && $source->stage?->slug === ($event->context['status'] ?? null),
            default => true,
        };
    }

    private function finish(AutomationLog $log, AutomationStep $step): void
    {
        $step->status = 'succeeded';
        $step->error_code = null;
        $step->retryable = false;
        $step->save();
        if ($log->steps()->where('status', '!=', 'succeeded')->exists()) {
            $this->stop($log, 'pending', null);
            $this->events->queueRun($log->id);
        } else {
            $this->stop($log, 'succeeded', null);
        }
    }

    private function stop(AutomationLog $log, string $status, ?string $error): void
    {
        $log->execution_status = $status;
        $log->error_message = $error;
        $log->executed_at = now();
        $log->lease_until = null;
        $log->lease_token = null;
        $log->save();
    }
}
