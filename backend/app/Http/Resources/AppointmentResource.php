<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'assigned_to' => $this->assigned_to, 'title' => $this->title, 'description' => $this->description, 'start_at' => $this->start_at->toISOString(), 'end_at' => $this->end_at->toISOString(), 'status' => $this->status, 'version' => $this->version, 'reminder_sent_at' => $this->reminder_sent_at?->toISOString(), 'assignee' => new PersonResource($this->whenLoaded('assignee')), 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'customer_code' => $this->customer->customer_code, 'first_name' => $this->customer->first_name, 'last_name' => $this->customer->last_name] : null)];
    }
}
