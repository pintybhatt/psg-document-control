<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Organizational data is initialized explicitly with organization:initialize-xppl.
     * Users and provisional departments must never be seeded automatically.
     */
    public function run(): void {}
}
