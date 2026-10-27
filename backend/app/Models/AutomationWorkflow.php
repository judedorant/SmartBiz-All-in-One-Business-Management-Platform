<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AutomationWorkflow extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['name', 'trigger_type', 'configuration', 'is_active'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'is_active' => 'boolean', 'version' => 'integer', 'created_by' => 'integer'];
    }

    protected $attributes = ['version' => 1, 'is_active' => false];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'workflow_id');
    }
}
