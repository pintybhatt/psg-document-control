<?php

namespace App\Console\Commands;

use App\Authorization\InitializeRoleDefinitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rbac:initialize')]
#[Description('Initialize the controlled permission catalog and unassigned role templates without overwriting configuration')]
class InitializeRoles extends Command
{
    public function handle(InitializeRoleDefinitions $definitions): int
    {
        $definitions->run();
        $this->info('Role definitions initialized. No accounts or role assignments were created.');

        return self::SUCCESS;
    }
}
