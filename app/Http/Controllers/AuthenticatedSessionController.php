<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return response()->view('app', ['page' => 'login', 'pages' => []])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $user->update(['preferred_language' => $request->validated('language')]);
        $request->session()->put('auth.session_version', $user->session_version);

        return redirect()->intended(route('dcms.dashboard', [], false))->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $language = in_array($request->input('language'), ['en', 'lo'], true)
            ? $request->input('language') : $request->user()->preferred_language;
        $request->user()->update(['preferred_language' => $language]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('language', $language);

        return redirect()->route('login')->header('Cache-Control', 'no-store, private');
    }
}
