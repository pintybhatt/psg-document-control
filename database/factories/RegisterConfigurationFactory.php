<?php

namespace Database\Factories;

use App\Models\AuthorizationScope;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegisterConfiguration> */
class RegisterConfigurationFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => AuthorizationScope::factory(), 'register_key' => 'incoming',
            'description_en' => null, 'description_lo' => null, 'active' => true,
            'created_by' => User::factory(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }
}
