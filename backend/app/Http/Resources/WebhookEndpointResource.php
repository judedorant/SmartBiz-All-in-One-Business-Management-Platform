<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'direction' => $this->direction, 'endpoint_url' => $this->endpoint_url, 'receive_url' => $this->direction === 'inbound' ? route('api.v1.webhooks.receive', ['endpoint' => $this->id]) : null, 'is_active' => $this->is_active, 'version' => $this->version, 'updated_at' => $this->updated_at?->toISOString()];
    }
}
