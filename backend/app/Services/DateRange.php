<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class DateRange
{
    public function __construct(public readonly CarbonImmutable $start, public readonly CarbonImmutable $end, public readonly string $timezone, public readonly string $preset, public readonly string $currency, public readonly CarbonImmutable $now) {}

    public static function from(array $input): self
    {
        $tz = $input['timezone'] ?? 'UTC';
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($tz)->startOfDay();
        $preset = $input['preset'] ?? 'last_30_days';
        $start = match ($preset) {
            'today' => $today,'last_7_days' => $today->subDays(6)->startOfDay(),'this_month' => $today->startOfMonth(),'custom' => CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], $tz),default => $today->subDays(29)->startOfDay()
        };
        $end = $preset === 'custom' ? CarbonImmutable::createFromFormat('!Y-m-d', $input['end_date'], $tz)->addDay()->startOfDay() : $today->addDay()->startOfDay();

        return new self($start->utc(), $end->utc(), $tz, $preset, $input['currency'] ?? config('smartbiz.default_currency'), $now);
    }

    public function metadata(): array
    {
        return ['preset' => $this->preset, 'start_date' => $this->start->setTimezone($this->timezone)->format('Y-m-d'), 'end_date' => $this->end->setTimezone($this->timezone)->subDay()->format('Y-m-d'), 'timezone' => $this->timezone, 'currency' => $this->currency, 'available_currencies' => config('smartbiz.currencies'), 'generated_at' => $this->now->toISOString()];
    }

    /** @return array<int, array{label:string,end:string}> */
    public function buckets(bool $monthly = false): array
    {
        $cursor = $this->start->setTimezone($this->timezone);
        $end = $this->end->setTimezone($this->timezone);
        $buckets = [];
        while ($cursor->lessThan($end)) {
            $next = $monthly ? $cursor->startOfMonth()->addMonth()->startOfMonth() : $cursor->addDay()->startOfDay();
            if ($next->greaterThan($end)) {
                $next = $end;
            }
            $buckets[] = ['label' => $cursor->format($monthly ? 'Y-m' : 'Y-m-d'), 'end' => $next->utc()->format('Y-m-d H:i:s')];
            $cursor = $next;
        }

        return $buckets;
    }
}
