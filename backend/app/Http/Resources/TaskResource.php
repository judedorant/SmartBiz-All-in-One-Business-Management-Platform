<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'assigned_to' => $this->assigned_to, 'created_by' => $this->created_by, 'customer_id' => $this->customer_id, 'lead_id' => $this->lead_id, 'priority' => $this->priority, 'status' => $this->status, 'version' => $this->version,
            'due_date' => $this->due_date?->toISOString(), 'completed_at' => $this->completed_at?->toISOString(), 'is_overdue' => $this->due_date && $this->due_date->isPast() && in_array($this->status, ['pending', 'in_progress'], true), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'assignee' => new PersonResource($this->whenLoaded('assignee')), 'creator' => new PersonResource($this->whenLoaded('creator')),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'first_name' => $this->customer->first_name, 'last_name' => $this->customer->last_name, 'customer_code' => $this->customer->customer_code] : null),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? ['id' => $this->lead->id, 'title' => $this->lead->title] : null),
        ];
    }
}
