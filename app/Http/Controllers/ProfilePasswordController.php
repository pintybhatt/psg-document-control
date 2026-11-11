<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Self-service password change. Saving rotates session_version, which signs out every other session; this one is kept. */
class ProfilePasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
            'password' => ['bail', 'required', 'string', Password::min(8), 'confirmed', 'different:current_password', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('profilePasswordTooLong');
                }
            }],
        ], [
            'current_password.required' => 'profilePasswordCurrentRequired',
            'current_password.current_password' => 'profilePasswordCurrentInvalid',
            'password.required' => 'profilePasswordNewRequired',
            'password.min' => 'profilePasswordTooShort',
            'password.confirmed' => 'profilePasswordMismatch',
            'password.different' => 'profilePasswordSame',
        ])->validate();

        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();

        $request->session()->regenerate();
        $request->session()->put('auth.session_version', $user->session_version);

        return response()->json(['message' => 'profilePasswordUpdated'])->header('Cache-Control', 'no-store, private');
    }
}
