<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationLog extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['execution_data' => 'array', 'executed_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id')->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class, 'event_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class, 'log_id');
    }
}
