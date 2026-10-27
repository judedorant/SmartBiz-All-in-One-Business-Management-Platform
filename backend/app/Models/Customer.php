<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'status'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            $customer->customer_code ??= 'CUS-'.Str::ulid();
        });
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('customers.manage')) {
            return;
        }
        if (! $user->can('customers.view_assigned')) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function (Builder $q) use ($user): void {
            $q->whereHas('assignees', fn (Builder $staff) => $staff->where('users.id', $user->id))
                ->orWhereHas('leads', fn (Builder $leads) => $leads->where('assigned_to', $user->id))
                ->orWhereHas('appointments', fn (Builder $appointments) => $appointments->where('assigned_to', $user->id))
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->where('assigned_to', $user->id));
        });
    }
}
