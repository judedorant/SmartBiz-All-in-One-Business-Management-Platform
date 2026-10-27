<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'workflow_id' => $this->workflow_id, 'workflow_version' => $this->workflow_version, 'execution_status' => $this->execution_status, 'execution_data' => $this->execution_data, 'error_message' => $this->error_message, 'executed_at' => $this->executed_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(), 'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => ['id' => $step->id, 'action_type' => $step->action_type, 'action_index' => $step->action_index, 'status' => $step->status, 'attempts' => $step->attempts, 'retryable' => $step->retryable, 'error_code' => $step->error_code]))];
    }
}
