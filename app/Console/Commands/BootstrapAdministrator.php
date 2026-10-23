<?php

namespace App\Console\Commands;

use App\Models\User;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('user:bootstrap-admin
    {--name= : Initial account display name}
    {--email= : Initial account login email}
    {--language=en : Preferred language (en or lo)}')]
#[Description('Create the initial identity using hidden password prompts; grants no roles or privileges')]
class BootstrapAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identity = [
            'name' => $this->option('name'),
            'email' => Str::lower(trim((string) $this->option('email'))),
            'preferred_language' => $this->option('language'),
        ];
        $validator = Validator::make($identity, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'preferred_language' => ['required', Rule::in(['en', 'lo'])],
        ]);

        if ($validator->fails()) {
            $this->error('Supply a valid name, email and language (en or lo).');

            return self::FAILURE;
        }

        if (User::query()->where('email', $identity['email'])->exists()) {
            $this->info('Account already exists. Nothing was changed and no permissions were granted.');

            return self::SUCCESS;
        }

        if (User::query()->exists()) {
            $this->error('Bootstrap is closed because an account already exists. Use the future authorized account-management process.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal with hidden password input is required. No password option or default is available.');

            return self::FAILURE;
        }

        $password = $this->secret('Password', false);
        $confirmation = $this->secret('Confirm password', false);
        $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => [
                'bail', 'required', 'string', Password::min(16), 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72 || str_contains($value, "\0")) {
                        $fail('Password must not exceed 72 bytes or contain null bytes.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            $this->error('Password must be at least 16 characters, at most 72 bytes, contain no null bytes, and match its confirmation.');

            return self::FAILURE;
        }

        $lock = Cache::lock('dcms:initial-user-bootstrap', 30);

        if (! $lock->get()) {
            $this->error('Another bootstrap operation is in progress. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $created = DB::transaction(function () use ($identity, $password): bool {
                if (User::query()->lockForUpdate()->exists()) {
                    return false;
                }

                User::query()->create($identity + ['password' => $password]);

                return true;
            }, 3);
        } finally {
            $lock->release();
        }

        if (! $created) {
            $this->error('An account was created concurrently. Nothing was changed.');

            return self::FAILURE;
        }

        $this->info('Initial identity created. No role, site assignment or document privileges were granted. Phase 2.2C must authorize the security administrator.');

        return self::SUCCESS;
    }
}
