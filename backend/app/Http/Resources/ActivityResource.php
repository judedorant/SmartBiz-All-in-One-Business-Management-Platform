<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'activity_type' => $this->activity_type, 'description' => $this->description, 'user' => new PersonResource($this->whenLoaded('user')), 'created_at' => $this->created_at?->toISOString()];
    }
}
