<?php

namespace Database\Factories;

use App\Authorization\Confidentiality;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentSecurityProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConfidentialityClearance> */
class ConfidentialityClearanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'site_id' => Site::factory(),
            'company_id' => fn (array $attributes): int => Site::query()->findOrFail($attributes['site_id'])->company_id,
            'department_id' => null, 'classification' => Confidentiality::Confidential, 'active' => true, 'expires_at' => null,
            'created_by' => User::factory(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }

    public function forProfile(DocumentSecurityProfile $profile): static
    {
        return $this->state(fn (): array => ['company_id' => $profile->company_id, 'site_id' => $profile->site_id,
            'department_id' => $profile->department_id, 'classification' => $profile->classification]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
