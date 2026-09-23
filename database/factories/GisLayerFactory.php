<?php

namespace Database\Factories;

use App\Models\GisLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GisLayer>
 */
class GisLayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'kind' => 'vector',
            'metadata' => [],
            'occurrences' => [],
        ];
    }
}
