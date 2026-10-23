<?php

namespace Database\Factories;

use App\Product;
use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'price' => $this->faker->numberBetween(200, 890),
            'quantity' => $this->faker->numberBetween(1, 7),
            'product_number' => (string) $this->faker->numberBetween(1000000, 9999999),
        ];
    }
}
