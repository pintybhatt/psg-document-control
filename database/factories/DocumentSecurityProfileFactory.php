<?php

namespace Database\Factories;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentResourceType;
use App\Models\Department;
use App\Models\DocumentSecurityProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentSecurityProfile> */
class DocumentSecurityProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['site_id' => Site::factory(), 'company_id' => fn (array $attributes): int => Site::query()->findOrFail($attributes['site_id'])->company_id,
            'department_id' => null, 'resource_type' => DocumentResourceType::Incoming, 'resource_key' => fake()->unique()->uuid(),
            'classification' => Confidentiality::Normal, 'restricted' => false, 'active' => true, 'created_by' => User::factory(),
            'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }

    public function classified(Confidentiality $classification): static
    {
        return $this->state(fn (): array => ['classification' => $classification, 'restricted' => $classification === Confidentiality::HighlyConfidential]);
    }

    public function atSite(Site $site): static
    {
        return $this->state(fn (): array => ['company_id' => $site->company_id, 'site_id' => $site->id]);
    }

    public function inDepartment(Department $department): static
    {
        return $this->state(fn (): array => ['company_id' => $department->company_id, 'site_id' => $department->site_id, 'department_id' => $department->id]);
    }
}
