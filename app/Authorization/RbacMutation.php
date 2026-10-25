<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RbacMutation
{
    private bool $running = false;

    public function run(Closure $operation): mixed
    {
        if ($this->running) {
            return $operation();
        }

        return Cache::lock('dcms:rbac-mutation', 120)->block(5, function () use ($operation): mixed {
            $guardScope = AuthorizationScope::context('global')->persist();

            return DB::transaction(function () use ($operation, $guardScope): mixed {
                $this->running = true;
                try {
                    DB::table('authorization_scopes')->where('id', $guardScope->id)->lockForUpdate()->first();
                    $access = app(AccessControl::class);
                    $protected = AuthorizationScope::query()->get()->filter(fn (AuthorizationScope $scope): bool => $scope->isActive() && $access->administrators($scope) !== []);
                    $result = $operation();
                    foreach ($protected as $scope) {
                        if ($scope->isActive() && $access->administrators($scope) === []) {
                            throw ValidationException::withMessages(['authorization' => 'rbacLastAdministrator']);
                        }
                    }

                    return $result;
                } finally {
                    $this->running = false;
                }
            });
        });
    }
}
