<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['user_id' => $this->user_id, 'timezone' => $this->timezone, 'version' => $this->version, 'windows' => $this->whenLoaded('windows', fn () => $this->windows->map(fn ($w) => ['day_of_week' => $w->day_of_week, 'start_time' => substr($w->start_time, 0, 5), 'end_time' => substr($w->end_time, 0, 5)]))];
    }
}
