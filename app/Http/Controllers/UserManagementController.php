<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\ManageUserOnboarding;
use App\Authorization\MembershipAccess;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\OrganizationMembership;
use App\Models\RbacAudit;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    public function index(Request $request, AccessControl $access): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = User::query();
        if (! empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        $users = $query->orderBy('name')->orderBy('id')->paginate(10, ['id', 'name', 'email', 'active', 'preferred_language']);

        return response()->json($users);
    }

    public function store(Request $request, AccessControl $access, RbacMutation $mutation, ManageUserOnboarding $onboarding): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $data = $this->validated($request, null);
        $initialAccess = $data['access'] ?? null;
        unset($data['access']);
        $user = $mutation->run(function () use ($request, $data, $initialAccess, $onboarding, $access): User {
            $this->authorizeAdministrator($request, $access);
            $user = User::query()->create($data);
            if ($initialAccess !== null) {
                $onboarding->assign($request->user(), $user->refresh(), $initialAccess);
            }
            $this->audit($request, 'user.created', $user, null);

            return $user;
        });

        return response()->json(['data' => $this->publicData($user)], 201);
    }

    public function update(Request $request, string $user, AccessControl $access, RbacMutation $mutation, ManageUserOnboarding $onboarding): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $target = User::query()->findOrFail($user);
        $data = $this->validated($request, $target);
        abort_if($target->id === $request->user()->id && array_key_exists('active', $data), 403, 'rbacSelfChange');
        $initialAccess = $data['access'] ?? null;
        unset($data['access']);
        $updated = $mutation->run(function () use ($request, $target, $data, $initialAccess, $onboarding, $access): User {
            $this->authorizeAdministrator($request, $access);
            $target->refresh();
            $before = $this->publicData($target);
            $target->forceFill($data)->save();
            if ($initialAccess !== null) {
                $onboarding->assign($request->user(), $target, $initialAccess);
            }
            $this->audit($request, 'user.updated', $target, $before, array_key_exists('password', $data));

            return $target;
        });

        return response()->json(['data' => $this->publicData($updated)]);
    }

    public function access(Request $request, string $user, AccessControl $access, MembershipAccess $memberships): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $target = User::query()->findOrFail($user);
        $units = $target->memberships()->whereNull('revoked_at')->with(['company', 'site', 'department'])->get()->map(function (OrganizationMembership $membership) use ($request, $access, $memberships): array {
            $scope = $membership->authorizationScope();

            return $membership->only(['id', 'active']) + [
                'effective' => $memberships->effective($membership),
                'company' => $membership->company->only(['name_en', 'name_lo']), 'site' => $membership->site?->only(['name_en', 'name_lo']),
                'department' => $membership->department?->only(['name_en', 'name_lo']),
                'revoke_url' => $membership->user_id !== $request->user()->id && $access->allows($request->user(), 'memberships.manage', $scope)
                    ? route('api.v1.memberships.destroy', ['membership' => $membership->id] + $scope->only(['scope_type', 'company_id', 'site_id']), false) : null,
            ];
        });
        $assignments = $target->roleAssignments()->where('active', true)->with(['role.scope', 'scope.company', 'scope.site'])->get()->map(function (UserRoleAssignment $assignment) use ($request, $access, $target): array {
            return ['id' => $assignment->id, 'role' => ['name_en' => $assignment->role->displayName('en'), 'name_lo' => $assignment->role->displayName('lo')],
                'scope_key' => $assignment->scope->scope_key,
                'company' => $assignment->scope->company?->only(['name_en', 'name_lo']), 'site' => $assignment->scope->site?->only(['name_en', 'name_lo']),
                'effective' => $target->active && $assignment->role->active && $assignment->role->scope->covers($assignment->scope),
                'revoke_url' => $assignment->user_id !== $request->user()->id && $access->allows($request->user(), 'assignments.manage', $assignment->scope)
                    ? route('api.v1.assignments.destroy', ['assignment' => $assignment->id] + $assignment->scope->only(['scope_type', 'company_id', 'site_id']), false) : null];
        });

        return response()->json(['memberships' => $units, 'assignments' => $assignments]);
    }

    private function authorizeAdministrator(Request $request, AccessControl $access): void
    {
        abort_unless($request->user() && $access->isSystemAdministrator($request->user()), 403, 'rbacForbidden');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user): array
    {
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $rules = [
            'access' => ['sometimes', 'array:company_id,site_id,department_id,role_id,confirm_regrant', 'required_array_keys:company_id,site_id,role_id'],
            'access.company_id' => ['required_with:access', 'integer', 'min:1'],
            'access.site_id' => ['required_with:access', 'integer', 'min:1'],
            'access.department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'access.role_id' => ['required_with:access', 'integer', 'min:1'],
            'access.confirm_regrant' => ['sometimes', 'boolean'],
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'preferred_language' => ['sometimes', Rule::in(['en', 'lo'])],
            'active' => [$user ? 'sometimes' : 'prohibited', 'boolean'],
            'password' => ['bail', $user ? 'sometimes' : 'required', 'string', Password::min(8), 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('Password must be no more than 72 bytes and contain no null bytes.');
                }
            }],
        ];
        $messages = [];
        foreach (['access', 'access.*'] as $attribute) {
            foreach (['array', 'required_array_keys', 'required_with', 'integer', 'min', 'boolean'] as $rule) {
                $messages[$attribute.'.'.$rule] = 'userAccessInvalid';
            }
        }
        $data = Validator::make($request->all(), $rules, $messages)->validate();
        unset($data['password_confirmation']);

        return $data;
    }

    /** @return array{id: int, name: string, email: string, active: bool, preferred_language: string} */
    private function publicData(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'active', 'preferred_language']);
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, string $action, User $user, ?array $before, bool $passwordChanged = false): void
    {
        $scope = AuthorizationScope::context('global')->persist();
        RbacAudit::query()->create([
            'actor_id' => $request->user()->id,
            'source' => 'web',
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'scope_id' => $scope->id,
            'before' => $before,
            'after' => $this->publicData($user) + ['password_changed' => $passwordChanged],
        ]);
    }
}
