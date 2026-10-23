<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'language' => ['required', Rule::in(['en', 'lo'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
        if (in_array($this->input('language'), ['en', 'lo'], true)) {
            $this->session()->put('language', $this->input('language'));
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'authEmailRequired',
            'email.string' => 'authEmailInvalid',
            'email.email' => 'authEmailInvalid',
            'email.max' => 'authEmailInvalid',
            'password.required' => 'authPasswordRequired',
            'password.string' => 'authInvalidCredentials',
            'password.max' => 'authInvalidCredentials',
            'language.required' => 'authLanguageInvalid',
            'language.in' => 'authLanguageInvalid',
        ];
    }

    public function authenticate(): void
    {
        $identityKey = 'login:'.hash('sha256', $this->string('email').'|'.$this->ip());
        $ipKey = 'login-ip:'.hash('sha256', (string) $this->ip());

        if (RateLimiter::tooManyAttempts($identityKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages(['email' => 'authThrottled'])->status(429);
        }

        RateLimiter::hit($ipKey, 60);

        $password = $this->validated('password');

        if (strlen($password) > 72 || str_contains($password, "\0")
            || ! Auth::guard('web')->attempt($this->only('email', 'password') + ['active' => true], false)) {
            RateLimiter::hit($identityKey, 60);

            throw ValidationException::withMessages(['email' => 'authInvalidCredentials']);
        }

        RateLimiter::clear($identityKey);
    }
}
