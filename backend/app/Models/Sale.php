<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'lead_id', 'amount', 'currency', 'closed_at'];

    protected $attributes = ['status' => 'completed', 'version' => 1];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'closed_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeVisibleTo(Builder $q, User $user): void
    {
        if ($user->can('sales.manage')) {
            return;
        }
        if (! $user->can('dashboard.view') || ! $user->can('leads.update_assigned')) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->whereHas('lead', fn (Builder $lead) => $lead->visibleTo($user))->whereHas('customer', fn (Builder $customer) => $customer->visibleTo($user));
    }
}
