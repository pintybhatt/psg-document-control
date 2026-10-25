<?php

namespace Database\Factories;

use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NumberingSeries> */
class NumberingSeriesFactory extends Factory
{
    public function definition(): array
    {
        return ['register_id' => RegisterConfiguration::factory(),
            'scope_id' => fn (array $attributes): int => RegisterConfiguration::query()->findOrFail($attributes['register_id'])->scope_id,
            'purpose' => 'internal_register', 'reset_policy' => 'annual', 'created_by' => User::factory()];
    }
}
