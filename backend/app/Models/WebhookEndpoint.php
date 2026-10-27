<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookEndpoint extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['name', 'direction', 'endpoint_url', 'is_active'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    protected $hidden = ['secret'];

    protected $attributes = ['version' => 1, 'is_active' => true];
}
