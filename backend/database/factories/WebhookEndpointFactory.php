<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'direction' => 'inbound', 'secret' => Str::random(48), 'is_active' => true, 'created_by' => User::factory()];
    }
}
