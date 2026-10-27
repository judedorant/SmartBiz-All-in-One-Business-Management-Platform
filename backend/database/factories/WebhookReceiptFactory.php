<?php

namespace Database\Factories;

use App\Models\WebhookEndpoint;
use App\Models\WebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookReceipt> */
class WebhookReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['webhook_endpoint_id' => WebhookEndpoint::factory(), 'delivery_id' => fake()->uuid(), 'nonce' => fake()->uuid(), 'body_hash' => fake()->sha256()];
    }
}
