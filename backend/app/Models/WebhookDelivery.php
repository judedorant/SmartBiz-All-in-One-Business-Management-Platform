<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'last_attempt_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    protected $hidden = ['payload'];
}
