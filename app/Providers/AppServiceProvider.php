<?php

namespace App\Providers;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RbacMutation::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (PermissionCatalog::definitions() as $key => $definition) {
            Gate::define($key, fn (User $user, ?AuthorizationScope $scope = null, ?int $departmentId = null): bool => $scope !== null && app(AccessControl::class)->allows($user, $key, $scope, $departmentId));
        }
    }
}
