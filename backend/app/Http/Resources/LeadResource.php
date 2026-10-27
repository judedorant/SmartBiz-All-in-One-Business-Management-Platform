<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'source' => $this->source, 'estimated_value' => $this->estimated_value, 'expected_close_date' => $this->expected_close_date?->format('Y-m-d'),
            'notes' => $this->notes, 'version' => $this->version, 'customer_id' => $this->customer_id, 'assigned_to' => $this->assigned_to, 'pipeline_stage_id' => $this->pipeline_stage_id,
            'status' => $this->whenLoaded('stage', fn () => $this->stage->slug), 'stage' => new PipelineStageResource($this->whenLoaded('stage')),
            'assignee' => new PersonResource($this->whenLoaded('assignee')),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'first_name' => $this->customer->first_name, 'last_name' => $this->customer->last_name, 'customer_code' => $this->customer->customer_code] : null),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'converted_at' => $this->converted_at?->toISOString(),
        ];
    }
}
