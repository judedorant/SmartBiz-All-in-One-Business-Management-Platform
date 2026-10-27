<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'provider' => $this->provider, 'display_email' => $this->display_email, 'status' => $this->status, 'updated_at' => $this->updated_at?->toISOString()];
    }
}
