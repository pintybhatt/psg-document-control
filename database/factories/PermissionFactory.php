<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['key' => 'roles.view', 'area' => 'roles', 'name_en' => 'View roles', 'name_lo' => 'ເບິ່ງບົດບາດ', 'active' => true];
    }
}
