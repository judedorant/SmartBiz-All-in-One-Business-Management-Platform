<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'lead_id' => $this->lead_id, 'amount' => $this->amount, 'currency' => $this->currency, 'status' => $this->status, 'version' => $this->version, 'closed_at' => $this->closed_at->toISOString(), 'voided_at' => $this->voided_at?->toISOString(), 'void_reason' => $this->void_reason,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'first_name' => $this->customer->first_name, 'last_name' => $this->customer->last_name] : null),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? ['id' => $this->lead->id, 'title' => $this->lead->title] : null), 'recorder' => new PersonResource($this->whenLoaded('recorder'))];
    }
}
