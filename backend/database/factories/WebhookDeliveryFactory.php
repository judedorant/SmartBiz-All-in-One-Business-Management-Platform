<?php

namespace Database\Factories;

use App\Models\AutomationStep;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['delivery_id' => fake()->uuid(), 'webhook_endpoint_id' => WebhookEndpoint::factory(), 'step_id' => AutomationStep::factory(), 'event_type' => 'lead_created', 'payload' => ['event' => 'lead_created'], 'status' => 'pending', 'attempts' => 0];
    }
}
