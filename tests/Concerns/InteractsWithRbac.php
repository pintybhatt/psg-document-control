<?php

namespace Tests\Concerns;

use App\Authorization\InitializeRoleDefinitions;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;

trait InteractsWithRbac
{
    protected function definitions(): void
    {
        app(InitializeRoleDefinitions::class)->run();
    }

    /** @param list<string> $keys */
    protected function grant(User $user, AuthorizationScope $scope, array $keys): Role
    {
        return app(RbacMutation::class)->run(function () use ($user, $scope, $keys): Role {
            $role = Role::factory()->create(['scope_id' => $scope->persist()->id]);
            $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all());
            UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => $scope->id, 'active' => true]);

            return $role;
        });
    }

    protected function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.session_version' => $user->session_version]);
    }

    /** @return array{scope_type: string, company_id: ?int, site_id: ?int} */
    protected function scopeData(AuthorizationScope $scope): array
    {
        return ['scope_type' => $scope->scope_type, 'company_id' => $scope->company_id, 'site_id' => $scope->site_id];
    }

    protected function scopedUrl(string $path, AuthorizationScope $scope): string
    {
        return '/api/v1/'.$path.'?'.http_build_query($this->scopeData($scope));
    }
}
