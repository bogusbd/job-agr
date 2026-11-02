<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectType;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(5);
        $budgetMin = fake()->numberBetween(10, 1000);
        $budgetMax = $budgetMin + fake()->numberBetween(0, 2000);

        return [
            'id' => fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'title' => $title,
            'seo_url' => 'web-development/'.Str::slug($title),
            'preview_description' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'type' => ProjectType::Fixed,
            'status' => 'active',
            'frontend_status' => 'open',
            'language' => 'en',
            'currency_code' => 'USD',
            'currency_sign' => '$',
            'exchange_rate' => 1,
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
            'budget_min_usd' => $budgetMin,
            'budget_max_usd' => $budgetMax,
            'bid_count' => fake()->numberBetween(0, 100),
            'bid_avg' => $budgetMax,
            'bid_avg_usd' => $budgetMax,
            'bid_period' => 7,
            'submitted_at' => fake()->dateTimeBetween('-7 days'),
            'remote_updated_at' => now(),
            'is_active' => true,
            'last_seen_at' => now(),
            'is_viewed' => false,
            'is_garbage' => false,
        ];
    }

    public function viewed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_viewed' => true,
            'viewed_at' => now(),
        ]);
    }

    public function garbage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_garbage' => true,
        ]);
    }

    public function hourly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ProjectType::Hourly,
            'hourly_hours' => 40,
            'hourly_interval' => 'week',
        ]);
    }
}
