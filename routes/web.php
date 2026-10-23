<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Middleware\EnsureActiveUserSession;
use App\Http\Middleware\EnsureDevelopmentEnvironment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

$pages = [
    'dashboard' => '/dashboard',
    'incoming' => '/incoming',
    'outgoing' => '/outgoing',
    'controlled-documents' => '/controlled-documents',
    'document-search' => '/document-search',
    'reports' => '/reports',
    'administration' => '/administration',
];

foreach ($pages as $page => $path) {
    Route::view($path, 'app', ['page' => $page, 'pages' => $pages])
        ->middleware(['auth:web', EnsureActiveUserSession::class])->name('dcms.'.$page);
}

Route::get('/dev/ui-components', function () use ($pages): View {
    return view('app', ['page' => 'ui-preview', 'pages' => $pages]);
})->middleware([EnsureDevelopmentEnvironment::class, 'auth:web', EnsureActiveUserSession::class])->name('dcms.ui-preview');

Route::middleware(['auth:web', EnsureActiveUserSession::class])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/api/v1/user', CurrentUserController::class)->name('api.v1.user');
});
