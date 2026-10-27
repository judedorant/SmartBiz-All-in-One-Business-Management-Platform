<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['lead_id' => Lead::factory(), 'user_id' => User::factory(), 'activity_type' => 'note', 'description' => fake()->sentence()];
    }
}
