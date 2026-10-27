<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'user_id' => User::factory(), 'activity_type' => 'note', 'description' => fake()->sentence()];
    }
}
