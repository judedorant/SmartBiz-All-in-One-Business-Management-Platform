<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function save(int $userId, array $data, User $actor): StaffSchedule
    {
        return DB::transaction(function () use ($userId, $data, $actor): StaffSchedule {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $schedule = StaffSchedule::lockForUpdate()->find($userId);
            abort_if(($schedule?->version ?? 0) !== (int) $data['version'], 409, 'This availability changed. Reload before saving.');
            $this->validateWindows($data['windows']);
            $schedule ??= new StaffSchedule(['user_id' => $userId]);
            $schedule->timezone = $data['timezone'];
            $schedule->version = (int) $data['version'] + 1;
            $schedule->save();
            $schedule->windows()->delete();
            foreach ($data['windows'] as $w) {
                $schedule->windows()->create(['user_id' => $userId, 'day_of_week' => $w['day_of_week'], 'start_time' => $w['start_time'], 'end_time' => $w['end_time']]);
            }$schedule->load('windows');
            Appointment::where('assigned_to', $userId)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '>=', now())->lockForUpdate()->chunkById(100, function ($appointments) use ($schedule): void {
                foreach ($appointments as $appointment) {
                    if (! $this->fits($schedule, $appointment->start_at, $appointment->end_at)) {
                        abort(409, 'This change would exclude an existing upcoming appointment. Reschedule or cancel it first.');
                    }
                }
            });
            DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'availability.update:'.$userId, 'created_at' => now()]);

            return $schedule;
        }, 5);
    }

    public function currentSchedule(int $userId): ?StaffSchedule
    {
        $schedule = StaffSchedule::lockForUpdate()->find($userId);
        if ($schedule) {
            $schedule->setRelation('windows', StaffAvailability::where('user_id', $userId)->orderBy('id')->lockForUpdate()->get());
        }

        return $schedule;
    }

    public function fits(?StaffSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (! $schedule) {
            return false;
        }$from = $start->setTimezone($schedule->timezone);
        $to = $end->setTimezone($schedule->timezone);
        if ($from->toDateString() !== $to->toDateString()) {
            return false;
        }
        $points = [$from, $to];
        foreach ((new \DateTimeZone($schedule->timezone))->getTransitions($start->getTimestamp(), $end->getTimestamp()) ?: [] as $transition) {
            if ($transition['ts'] > $start->getTimestamp() && $transition['ts'] < $end->getTimestamp()) {
                $points[] = CarbonImmutable::createFromTimestampUTC($transition['ts'] - 1)->setTimezone($schedule->timezone);
                $points[] = CarbonImmutable::createFromTimestampUTC($transition['ts'])->setTimezone($schedule->timezone);
            }
        }
        foreach ($schedule->windows as $window) {
            $a = strlen($window->start_time) === 5 ? $window->start_time.':00' : $window->start_time;
            $b = strlen($window->end_time) === 5 ? $window->end_time.':00' : $window->end_time;
            if ($window->day_of_week === $from->dayOfWeek && collect($points)->every(fn ($point) => $point->toDateString() === $from->toDateString() && $point->format('H:i:s') >= $a && $point->format('H:i:s') <= $b)) {
                return true;
            }
        }

        return false;
    }

    private function validateWindows(array $windows): void
    {
        usort($windows, fn (array $a, array $b) => [$a['day_of_week'], $a['start_time']] <=> [$b['day_of_week'], $b['start_time']]);
        $previous = null;
        foreach ($windows as $window) {
            if ($window['end_time'] <= $window['start_time'] || ($previous && (int) $previous['day_of_week'] === (int) $window['day_of_week'] && $window['start_time'] < $previous['end_time'])) {
                throw ValidationException::withMessages(['windows' => ['Availability windows must be ordered within a day and cannot overlap.']]);
            }$previous = $window;
        }
    }
}
