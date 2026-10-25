<?php

namespace App\Console\Commands;

use App\Authorization\InitializeRoleDefinitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('rbac:initialize {--enable-membership-administration} {--organization-context-role=} {--operator=} {--confirm-global}')]
#[Description('Initialize catalog and templates; optionally enable explicitly reviewed capabilities on existing roles')]
class InitializeRoles extends Command
{
    public function handle(InitializeRoleDefinitions $definitions): int
    {
        if ($this->option('enable-membership-administration') || $this->option('organization-context-role') !== null) {
            $operator = trim((string) $this->option('operator'));
            if ($operator === '' || mb_strlen($operator) > 255 || ! $this->option('confirm-global')) {
                $this->error('Enabling new capabilities changes existing role grants. Supply --operator and --confirm-global after reviewing the role and its assignments.');

                return self::FAILURE;
            }
            if ($this->option('enable-membership-administration') && $this->option('organization-context-role') !== null) {
                $this->error('Enable administrative and operational-context capabilities in separate reviewed commands.');

                return self::FAILURE;
            }
            try {
                if ($this->option('enable-membership-administration')) {
                    $definitions->enableMembershipAdministration($operator);
                } else {
                    $roleCode = (string) $this->option('organization-context-role');
                    if (! preg_match('/^[A-Z][A-Z0-9_-]{1,63}$/', $roleCode)) {
                        $this->error('Supply the stable code of a verified existing role.');

                        return self::FAILURE;
                    }
                    $definitions->enableOrganizationContext($roleCode, $operator);
                }
            } catch (ValidationException $exception) {
                $this->error($exception->validator->errors()->first());

                return self::FAILURE;
            }
        } else {
            $definitions->run();
        }
        $this->info('Role definitions initialized. No accounts or role assignments were created.');

        return self::SUCCESS;
    }
}
