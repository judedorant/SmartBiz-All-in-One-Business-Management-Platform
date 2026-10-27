<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'assigned_to' => User::factory(), 'created_by' => User::factory(), 'priority' => 'medium', 'status' => 'pending', 'due_date' => now()->addDay()];
    }
}
