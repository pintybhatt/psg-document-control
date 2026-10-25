<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserRoleAssignment> */
class UserRoleAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope_id' => AuthorizationScope::factory(),
            'role_id' => fn (array $attributes): int => Role::factory()->create(['scope_id' => $attributes['scope_id']])->id,
            'user_id' => User::factory(), 'active' => true,
        ];
    }
}
