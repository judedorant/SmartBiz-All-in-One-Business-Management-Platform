<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SaleFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'recorded_by' => User::factory(), 'amount' => '1234.56', 'currency' => 'USD', 'status' => 'completed', 'closed_at' => now(), 'request_id' => (string) Str::uuid()];
    }
}
