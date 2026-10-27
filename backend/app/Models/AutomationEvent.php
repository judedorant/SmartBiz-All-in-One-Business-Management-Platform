<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomationEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'subject_id' => 'integer', 'subject_version' => 'integer'];
    }
}
