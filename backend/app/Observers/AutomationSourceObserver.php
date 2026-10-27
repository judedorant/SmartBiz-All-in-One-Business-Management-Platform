<?php

namespace App\Observers;

use App\Events\AutomationTriggered;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;

class AutomationSourceObserver
{
    public function created(Model $model): void
    {
        $type = match (true) {
            $model instanceof Customer => 'customer',$model instanceof Lead => 'lead',$model instanceof Appointment => 'appointment',default => null
        };
        if ($type) {
            AutomationTriggered::dispatch($type.'_created', $type, $model->id, (int) ($model->version ?? 1));
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Appointment) {
            AutomationTriggered::dispatch('appointment_updated', 'appointment', $model->id, $model->version, ['archived' => true]);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Appointment && $model->wasChanged(['title', 'start_at', 'end_at', 'status', 'assigned_to'])) {
            AutomationTriggered::dispatch('appointment_updated', 'appointment', $model->id, $model->version);
        }
        if ($model instanceof Lead && $model->wasChanged('pipeline_stage_id')) {
            AutomationTriggered::dispatch('lead_status_changed', 'lead', $model->id, $model->version, ['status' => $model->stage()->value('slug')]);
        }
    }
}
