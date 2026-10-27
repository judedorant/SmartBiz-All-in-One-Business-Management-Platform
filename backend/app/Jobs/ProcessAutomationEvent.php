<?php

namespace App\Jobs;

use App\Services\AutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAutomationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        app(AutomationService::class)->process($this->eventId);
    }
}
