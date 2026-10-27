<?php

namespace Database\Factories;

use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(4), 'source' => 'Referral', 'estimated_value' => '1234.56', 'assigned_to' => User::factory(), 'created_by' => User::factory(), 'pipeline_stage_id' => PipelineStage::where('slug', 'new')->value('id') ?? PipelineStage::factory()];
    }
}
