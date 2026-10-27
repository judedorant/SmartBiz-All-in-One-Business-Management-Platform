<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory,SoftDeletes;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = ['title', 'description', 'assigned_to', 'created_by', 'customer_id', 'lead_id', 'priority', 'status', 'due_date', 'completed_at'];

    protected $attributes = ['status' => 'pending', 'priority' => 'medium', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'assigned_to' => 'integer', 'created_by' => 'integer', 'customer_id' => 'integer', 'lead_id' => 'integer', 'due_date' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): void
    {
        if ($user->can('tasks.assign')) {
            return;
        }
        if (! $user->can('tasks.manage_assigned')) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->where('assigned_to', $user->id);
    }
}
