<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMembership> */
class OrganizationMembershipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'membership_type' => 'company', 'company_id' => Company::factory(),
            'site_id' => null, 'department_id' => null, 'active' => true, 'created_by' => User::factory(),
            'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }

    public function atSite(Site $site): static
    {
        return $this->state(fn (): array => ['membership_type' => 'site', 'company_id' => $site->company_id, 'site_id' => $site->id]);
    }

    public function inDepartment(Department $department): static
    {
        return $this->state(fn (): array => ['membership_type' => 'department', 'company_id' => $department->company_id, 'site_id' => $department->site_id, 'department_id' => $department->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
