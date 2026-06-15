<?php

namespace Database\Factories;

use App\Models\Notebook;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory(),
            'page_number' => $this->faker->unique()->numberBetween(1, 1000),
            'stroke_data' => [],
            'header_data' => null,
            'footer_data' => null,
        ];
    }
}