<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
final class SkillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'id' => fake()->unique()->numberBetween(1, 100_000),
            'name' => $name,
            'seo_url' => Str::slug($name, '_'),
            'category_id' => 1,
            'category_name' => 'Websites, IT & Software',
        ];
    }
}
