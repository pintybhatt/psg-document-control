<?php

namespace Database\Seeders;

use App\Authorization\InitializeRoleDefinitions;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Local development only: creates (or reuses) one account and assigns it the global
 * System Administrator role, which grants every available permission in the system.
 *
 * It is deliberately not called from DatabaseSeeder. Run it explicitly:
 *   php artisan db:seed --class=SuperAdministratorSeeder
 *
 * Configure in .env (no defaults; the password is only needed when the account does not exist yet):
 *   SUPER_ADMIN_EMAIL=admin@example.test
 *   SUPER_ADMIN_NAME="System Administrator"
 *   SUPER_ADMIN_PASSWORD=...
 *   SUPER_ADMIN_LANGUAGE=en
 *
 * Production and UAT must keep using the audited CLI flow:
 *   user:bootstrap-admin, rbac:initialize, rbac:bootstrap-admin --scope=global --confirm-global
 */
class SuperAdministratorSeeder extends Seeder
{
    private const OPERATOR = 'SuperAdministratorSeeder (local development)';

    public function run(InitializeRoleDefinitions $definitions): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('SuperAdministratorSeeder runs only in the local or testing environment. Use the rbac:bootstrap-admin command elsewhere.');
        }

        $identity = [
            'name' => trim((string) env('SUPER_ADMIN_NAME', 'System Administrator')),
            'email' => Str::lower(trim((string) env('SUPER_ADMIN_EMAIL', ''))),
            'preferred_language' => (string) env('SUPER_ADMIN_LANGUAGE', 'en'),
        ];
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        $validator = Validator::make($identity, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'preferred_language' => ['required', Rule::in(['en', 'lo'])],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Set SUPER_ADMIN_EMAIL (and optionally SUPER_ADMIN_NAME, SUPER_ADMIN_LANGUAGE=en|lo) in .env: '.$validator->errors()->first());
        }

        // 1. Permission catalog and role templates, plus membership administration for the System Administrator role.
        $definitions->run();
        $definitions->enableMembershipAdministration(self::OPERATOR);

        // 2. The account. An existing account is reused and its password is never changed.
        $user = User::query()->where('email', $identity['email'])->first();
        if (! $user) {
            if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new RuntimeException('Set SUPER_ADMIN_PASSWORD in .env (8 to 72 bytes) to create the account.');
            }
            $user = User::query()->create($identity + ['password' => $password]);
            $this->command?->info("Created account {$user->email}.");
        } elseif (! $user->active) {
            throw new RuntimeException("Account {$user->email} is deactivated. Reactivate it through authorized administration first.");
        } else {
            $this->command?->info("Reusing existing account {$user->email}; its password was not changed.");
        }

        // 3. Global System Administrator assignment through the audited bootstrap command.
        $exitCode = Artisan::call('rbac:bootstrap-admin', [
            '--email' => $user->email,
            '--scope' => 'global',
            '--operator' => self::OPERATOR,
            '--confirm-global' => true,
        ]);
        $output = trim(Artisan::output());
        if ($exitCode !== 0) {
            throw new RuntimeException("Global System Administrator assignment failed: {$output}");
        }
        $this->command?->info($output);
    }
}
