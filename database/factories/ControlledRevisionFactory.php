<?php

namespace Database\Factories;

use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ControlledRevision> */
class ControlledRevisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['document_id' => ControlledDocument::factory(), 'revision_number' => 0, 'draft_slot' => 1,
            'title_en' => fake()->sentence(), 'title_lo' => 'ເອກະສານທົດສອບ', 'revision_reason' => 'Initial issue', 'change_description' => 'Initial controlled draft',
            'owner_id' => fn (array $attributes): int => ControlledDocument::findOrFail($attributes['document_id'])->created_by,
            'custodian_id' => fn (array $attributes): int => $attributes['owner_id'],
            'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'review_date' => '2027-10-26',
            'created_by' => fn (array $attributes): int => $attributes['owner_id'], 'updated_by' => fn (array $attributes): int => $attributes['owner_id']];
    }
}
