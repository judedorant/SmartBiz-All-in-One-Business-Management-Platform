<?php

namespace Database\Factories;

use App\Models\AutomationWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationWorkflow> */
class AutomationWorkflowFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'trigger_type' => 'lead_created', 'configuration' => ['actions' => [['type' => 'create_task', 'title' => 'Follow up {{title}}', 'priority' => 'high', 'due_in_hours' => 24]]], 'is_active' => false, 'created_by' => User::factory()];
    }
}
