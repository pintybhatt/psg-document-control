<?php

namespace Database\Factories;

use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentType> */
class DocumentTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['category_id' => DocumentCategory::factory(),
            'scope_id' => fn (array $attributes): int => DocumentCategory::query()->findOrFail($attributes['category_id'])->scope_id,
            'code' => fake()->unique()->bothify('TYPE-########'), 'name_en' => 'Example Type', 'name_lo' => 'ປະເພດເອກະສານຕົວຢ່າງ',
            'active' => true, 'created_by' => User::factory(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }
}
