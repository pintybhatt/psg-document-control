<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['scope_id' => AuthorizationScope::factory(), 'code' => fake()->unique()->bothify('ROLE-########'), 'name_en' => 'Example Role', 'name_lo' => 'ບົດບາດຕົວຢ່າງ', 'active' => true];
    }
}
