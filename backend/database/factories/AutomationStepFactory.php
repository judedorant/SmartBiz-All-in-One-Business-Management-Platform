<?php

namespace Database\Factories;

use App\Models\AutomationLog;
use App\Models\AutomationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationStep> */
class AutomationStepFactory extends Factory
{
    public function definition(): array
    {
        return ['log_id' => AutomationLog::factory(), 'action_index' => 0, 'action_type' => 'send_notification', 'configuration' => ['type' => 'send_notification'], 'status' => 'pending', 'attempts' => 0];
    }
}
