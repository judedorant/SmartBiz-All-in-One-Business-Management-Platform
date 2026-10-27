<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'position', 'probability'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'probability' => 'integer'];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
