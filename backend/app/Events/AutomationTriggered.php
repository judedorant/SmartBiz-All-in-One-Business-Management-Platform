<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AutomationTriggered
{
    use Dispatchable;

    public function __construct(public string $trigger, public string $subjectType, public int $subjectId, public int $version, public array $context = []) {}
}
