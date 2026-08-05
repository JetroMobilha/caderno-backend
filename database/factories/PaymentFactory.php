<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 1000, 5000),
            'payment_method' => 'multicaixa',
            'entity' => $this->faker->numerify('#####'),
            'reference' => $this->faker->numerify('#########'),
            'status' => $this->faker->randomElement(['pending', 'completed', 'failed']),
            'item_type' => 'subscription',
            'item_id' => null,
            'plan_type' => 'pro_monthly',
            'expires_at' => now()->addMonth(),
            'paid_at' => now(),
        ];
    }
}
