<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineStageFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Test stage', 'slug' => fake()->unique()->slug(3), 'position' => fake()->unique()->numberBetween(10, 250), 'probability' => 0];
    }
}
