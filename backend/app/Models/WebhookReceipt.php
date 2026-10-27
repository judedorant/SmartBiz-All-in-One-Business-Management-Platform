<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookReceipt extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return [];
    }
}
