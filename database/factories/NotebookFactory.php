<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotebookFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'title' => $this->faker->sentence(3),
            'cover_type' => 'basic',
            'color' => $this->faker->safeHexColor(),
            'cover_image' => null,
            'line_type' => 'grid',
        ];
    }
}