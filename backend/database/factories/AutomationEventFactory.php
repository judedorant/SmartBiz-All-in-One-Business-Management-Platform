<?php

namespace Database\Factories;

use App\Models\AutomationEvent;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationEvent> */
class AutomationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['event_key' => fake()->unique()->uuid(), 'trigger_type' => 'lead_created', 'subject_type' => 'lead', 'subject_id' => Lead::factory(), 'subject_version' => 1, 'context' => [], 'occurred_at' => now()];
    }
}
