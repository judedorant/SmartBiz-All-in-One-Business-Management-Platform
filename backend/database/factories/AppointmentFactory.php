<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'assigned_to' => User::factory(), 'title' => fake()->sentence(3), 'start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'status' => 'scheduled'];
    }
}
