<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffAvailabilityFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'];
    }
}
