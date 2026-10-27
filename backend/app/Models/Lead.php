<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'source', 'estimated_value', 'expected_close_date', 'notes', 'assigned_to', 'customer_id'];

    protected $attributes = ['version' => 1, 'estimated_value' => '0.00'];

    protected function casts(): array
    {
        return ['estimated_value' => 'decimal:2', 'expected_close_date' => 'date', 'converted_at' => 'datetime', 'version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('leads.manage')) {
            return;
        }
        if (! $user->can('leads.update_assigned')) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where('assigned_to', $user->id);
    }
}
