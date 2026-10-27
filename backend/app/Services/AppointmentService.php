<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private AvailabilityService $availability) {}

    public function store(User $actor, array $data): Appointment
    {
        return $this->mutate($actor, null, $data, 'create');
    }

    public function update(User $actor, int $id, array $data): Appointment
    {
        return $this->mutate($actor, $id, $data, 'update');
    }

    public function reschedule(User $actor, int $id, array $data): Appointment
    {
        return $this->mutate($actor, $id, $data, 'reschedule');
    }

    public function cancel(User $actor, int $id, int $version): Appointment
    {
        return $this->mutate($actor, $id, ['version' => $version], 'cancel');
    }

    public function delete(User $actor, int $id, int $version): void
    {
        $this->mutate($actor, $id, ['version' => $version], 'delete');
    }

    private function mutate(User $actor, ?int $id, array $data, string $operation): Appointment
    {
        $snapshot = $id ? Appointment::visibleTo($actor)->findOrFail($id) : null;

        return DB::transaction(function () use ($actor, $id, $data, $operation, $snapshot): Appointment {
            $ids = array_unique(array_filter([$snapshot?->assigned_to, $data['assigned_to'] ?? null]));
            sort($ids);
            $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $appointment = $id ? Appointment::visibleTo($actor)->lockForUpdate()->findOrFail($id) : new Appointment;
            Gate::forUser($actor)->authorize($id ? ($operation === 'delete' ? 'delete' : 'update') : 'create', $id ? $appointment : Appointment::class);
            if ($id) {
                abort_if($appointment->version !== (int) $data['version'], 409, 'This appointment changed. Reload it before retrying.');
            }
            $oldStart = $appointment->start_at;
            $oldEnd = $appointment->end_at;
            $oldOwner = $appointment->assigned_to;
            $oldStatus = $appointment->status;
            if ($operation === 'delete') {
                $appointment->version++;
                $appointment->save();
                $appointment->delete();
                $this->audit($actor, 'archive', $appointment);

                return $appointment;
            }
            if ($operation === 'cancel') {
                abort_if($appointment->status === 'completed', 409, 'A completed appointment cannot be cancelled.');
                $appointment->status = 'cancelled';
            } else {
                $appointment->fill($data);
            }
            if ($operation === 'reschedule') {
                abort_unless(in_array($oldStatus, ['scheduled', 'confirmed'], true), 409, 'Only active appointments can be rescheduled.');
            }
            $changed = ! $id || $oldOwner !== $appointment->assigned_to || ! $oldStart->equalTo($appointment->start_at) || ! $oldEnd->equalTo($appointment->end_at);
            if ($oldStatus === 'completed' && ($changed || $appointment->status !== 'completed')) {
                abort(409, 'Completed appointments keep their original time and status. Book a new appointment instead.');
            }
            if ($appointment->status === 'completed' && $appointment->end_at->isFuture()) {
                throw ValidationException::withMessages(['status' => ['An appointment cannot be completed before it ends.']]);
            }
            $blocking = in_array($appointment->status, ['scheduled', 'confirmed'], true);
            $reopening = $id && ! in_array($oldStatus, ['scheduled', 'confirmed'], true) && $blocking;
            if ($blocking && ($changed || $reopening)) {
                Customer::visibleTo($actor)->lockForUpdate()->findOrFail($appointment->customer_id);
                $owner = $users->get($appointment->assigned_to);
                if (! $owner || $owner->status !== 'active' || (! $owner->can('appointments.view_assigned') && ! $owner->can('appointments.manage'))) {
                    throw ValidationException::withMessages(['assigned_to' => ['Choose an active staff member with appointment access.']]);
                }
                $start = $appointment->start_at;
                $end = $appointment->end_at;
                $minutes = $start->diffInMinutes($end);
                if ($start->isPast() || $start->greaterThan(CarbonImmutable::now()->addYear()) || $minutes < 5 || $minutes > 480) {
                    throw ValidationException::withMessages(['start_at' => ['Book a future appointment within one year, lasting 5 minutes to 8 hours.']]);
                }
                if (! $this->availability->fits($this->availability->currentSchedule($appointment->assigned_to), $start, $end)) {
                    throw ValidationException::withMessages(['start_at' => ['The appointment must fit within one configured staff availability window.']]);
                }
                $conflict = Appointment::where('assigned_to', $appointment->assigned_to)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '<', $end)->where('end_at', '>', $start)->when($id, fn ($q) => $q->whereKeyNot($id))->lockForUpdate()->first(['id']);
                abort_if($conflict, 409, 'This staff member already has an appointment in that time slot.');
            }
            if (! $id) {
                $appointment->created_by = $actor->id;
            }$appointment->version = $id ? $appointment->version + 1 : 1;
            if ($changed || $reopening) {
                $appointment->reminder_sent_at = null;
            }$appointment->save();
            $this->audit($actor, $operation, $appointment);

            return $appointment;
        }, 5);
    }

    private function audit(User $actor, string $action, Appointment $appointment): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'appointments.'.$action.':'.$appointment->id, 'created_at' => now()]);
    }
}
