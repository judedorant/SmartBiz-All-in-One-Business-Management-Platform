<?php

namespace Database\Factories;

use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationAccount> */
class IntegrationAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'provider' => 'google_sheets', 'external_account_id' => fake()->uuid(), 'display_email' => fake()->safeEmail(), 'status' => 'connected', 'encrypted_credentials' => ['access_token' => fake()->sha256(), 'refresh_token' => fake()->sha256(), 'expires_at' => now()->addHour()->timestamp, 'scope' => 'https://www.googleapis.com/auth/spreadsheets']];
    }
}
