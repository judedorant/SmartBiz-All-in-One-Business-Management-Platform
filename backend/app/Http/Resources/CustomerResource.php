<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'customer_code' => $this->customer_code, 'first_name' => $this->first_name, 'last_name' => $this->last_name,
            'email' => $this->email, 'phone' => $this->phone, 'company' => $this->company, 'address' => $this->address, 'status' => $this->status, 'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'assignees' => PersonResource::collection($this->whenLoaded('assignees')), 'creator' => new PersonResource($this->whenLoaded('creator')),
        ];
    }
}
