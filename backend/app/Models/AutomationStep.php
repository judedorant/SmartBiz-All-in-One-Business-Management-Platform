<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationStep extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['configuration' => 'encrypted:array', 'retryable' => 'boolean', 'attempts' => 'integer'];
    }

    protected $hidden = ['configuration'];

    public function log(): BelongsTo
    {
        return $this->belongsTo(AutomationLog::class, 'log_id');
    }
}
