<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
final class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 99_999_999),
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'country_code' => 'us',
            'country_name' => 'United States',
            'registered_at' => fake()->dateTimeBetween('-5 years'),
            'is_payment_verified' => true,
            'is_email_verified' => true,
            'is_deposit_made' => true,
            'rating' => fake()->randomFloat(2, 0, 5),
            'reviews_count' => fake()->numberBetween(0, 50),
            'fetched_at' => now(),
        ];
    }
}
