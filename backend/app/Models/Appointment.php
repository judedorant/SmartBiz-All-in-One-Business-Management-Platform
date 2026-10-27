<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['customer_id', 'assigned_to', 'title', 'description', 'start_at', 'end_at', 'status', 'reminder_sent_at'];

    protected $attributes = ['status' => 'scheduled', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'assigned_to' => 'integer', 'customer_id' => 'integer', 'start_at' => 'immutable_datetime', 'end_at' => 'immutable_datetime', 'reminder_sent_at' => 'immutable_datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): void
    {
        if ($user->can('appointments.manage')) {
            return;
        }
        if (! $user->can('appointments.view_assigned')) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->where('assigned_to', $user->id);
    }
}
