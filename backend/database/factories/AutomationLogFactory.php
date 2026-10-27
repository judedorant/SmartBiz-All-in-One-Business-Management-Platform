<?php

namespace Database\Factories;

use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationLog> */
class AutomationLogFactory extends Factory
{
    public function definition(): array
    {
        return ['workflow_id' => AutomationWorkflow::factory(), 'event_id' => AutomationEvent::factory(), 'workflow_version' => 1, 'execution_status' => 'pending'];
    }
}
