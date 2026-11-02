<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\RbacAudit;
use App\Models\User;
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

    public function store(Request $request, AccessControl $access, RbacMutation $mutation): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $data = $this->validated($request, null);
        $user = $mutation->run(function () use ($request, $data): User {
            $user = User::query()->create($data);
            $this->audit($request, 'user.created', $user, null);

            return $user;
        });

        return response()->json(['data' => $this->publicData($user)], 201);
    }

    public function update(Request $request, string $user, AccessControl $access, RbacMutation $mutation): JsonResponse
    {
        $this->authorizeAdministrator($request, $access);
        $target = User::query()->findOrFail($user);
        $data = $this->validated($request, $target);
        abort_if($target->id === $request->user()->id && array_key_exists('active', $data), 403, 'rbacSelfChange');
        $updated = $mutation->run(function () use ($request, $target, $data): User {
            $target->refresh();
            $before = $this->publicData($target);
            $target->forceFill($data)->save();
            $this->audit($request, 'user.updated', $target, $before, array_key_exists('password', $data));

            return $target;
        });

        return response()->json(['data' => $this->publicData($updated)]);
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
        $data = Validator::make($request->all(), $rules)->validate();
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
