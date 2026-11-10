<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ManagedUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'revision' => $this->resource->managementRevision()];
    }
}
