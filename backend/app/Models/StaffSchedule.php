<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffSchedule extends Model
{
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'timezone'];

    protected $attributes = ['timezone' => 'UTC', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function windows(): HasMany
    {
        return $this->hasMany(StaffAvailability::class, 'user_id', 'user_id');
    }
}
