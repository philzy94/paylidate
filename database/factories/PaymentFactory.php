<?php

namespace Database\Factories;

use App\Payment;
use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payment_ref' => $this->faker->uuid(),
            'payment_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'verified' => true,
            'balance_before' => 0,
            'balance_after' => 200,
        ];
    }
}
