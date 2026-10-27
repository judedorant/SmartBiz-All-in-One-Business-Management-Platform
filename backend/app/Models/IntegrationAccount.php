<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationAccount extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['encrypted_credentials' => 'encrypted:array', 'user_id' => 'integer'];
    }

    protected $hidden = ['encrypted_credentials'];
}
