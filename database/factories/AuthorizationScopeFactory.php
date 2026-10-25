<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuthorizationScope> */
class AuthorizationScopeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['scope_type' => 'company', 'company_id' => Company::factory(), 'site_id' => null];
    }
}
