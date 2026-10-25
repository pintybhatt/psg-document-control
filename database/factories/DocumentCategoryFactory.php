<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentCategory> */
class DocumentCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => AuthorizationScope::factory(), 'code' => fake()->unique()->bothify('CATEGORY-########'), 'name_en' => 'Example Category', 'name_lo' => 'ໝວດເອກະສານຕົວຢ່າງ',
            'description_en' => null, 'description_lo' => null, 'active' => true,
            'created_by' => User::factory(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }
}
