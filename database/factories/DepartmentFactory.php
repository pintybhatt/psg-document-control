<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'company_id' => fn (array $attributes): int => Site::query()->findOrFail($attributes['site_id'])->company_id,
            'code' => fake()->unique()->bothify('DEPT-########'),
            'name_en' => fake()->words(2, true),
            'name_lo' => 'ພະແນກຕົວຢ່າງ',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
