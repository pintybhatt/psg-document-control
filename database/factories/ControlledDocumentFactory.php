<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\ControlledDocument;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\RegisterConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ControlledDocument> */
class ControlledDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => DocumentSecurityProfile::factory()->state(['resource_type' => 'controlled']),
            'scope_id' => function (array $attributes): int {
                $profile = DocumentSecurityProfile::findOrFail($attributes['profile_id']);

                return AuthorizationScope::context('site', $profile->company_id, $profile->site_id)->persist()->id;
            },
            'register_id' => fn (array $attributes): int => RegisterConfiguration::query()->where('scope_id', $attributes['scope_id'])->where('register_key', 'controlled')
                ->firstOr(fn (): RegisterConfiguration => RegisterConfiguration::factory()->create(['scope_id' => $attributes['scope_id'], 'register_key' => 'controlled']))->id,
            'type_id' => function (array $attributes): int {
                $category = DocumentCategory::factory()->create(['scope_id' => $attributes['scope_id']]);
                $type = DocumentType::factory()->create(['scope_id' => $attributes['scope_id'], 'category_id' => $category->id]);
                $type->registers()->attach($attributes['register_id'], ['scope_id' => $attributes['scope_id']]);

                return $type->id;
            },
            'document_code' => fake()->unique()->numerify('TEST/SOP/########'), 'status' => 'draft', 'lock_version' => 1,
            'created_by' => fn (array $attributes): int => DocumentSecurityProfile::findOrFail($attributes['profile_id'])->created_by,
            'updated_by' => fn (array $attributes): int => $attributes['created_by'],
        ];
    }
}
