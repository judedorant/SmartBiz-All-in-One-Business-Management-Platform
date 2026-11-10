<?php

namespace App\Models;

use App\Notifications\PasswordRecovery;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    protected $attributes = ['status' => 'active'];

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordRecovery($token));
    }

    public function managementRevision(): string
    {
        return hash_hmac('sha256', json_encode([$this->name, $this->email, $this->status, $this->password, $this->getRoleNames()->sort()->values()->all()], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function assignedCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_user')->withTimestamps();
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function createdCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime'];
    }
}
