<?php

namespace Database\Factories;

use App\Authorization\DocumentOperation;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentAccessGrant> */
class DocumentAccessGrantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['profile_id' => DocumentSecurityProfile::factory(), 'user_id' => User::factory(), 'operation' => DocumentOperation::Metadata,
            'active' => true, 'expires_at' => null, 'created_by' => User::factory(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }
}
