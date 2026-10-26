<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\AuthorizationContextController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Controllers\DocumentMasterDataController;
use App\Http\Controllers\IncomingCorrespondenceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\NumberingController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
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
    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::get('incoming-correspondence/contexts', [IncomingCorrespondenceController::class, 'contexts'])->name('incoming.contexts');
        Route::get('incoming-correspondence/lookups', [IncomingCorrespondenceController::class, 'lookups'])->name('incoming.lookups');
        Route::get('incoming-correspondence', [IncomingCorrespondenceController::class, 'index'])->name('incoming.index');
        Route::post('incoming-correspondence', [IncomingCorrespondenceController::class, 'store'])->name('incoming.store');
        Route::get('incoming-correspondence/{record}', [IncomingCorrespondenceController::class, 'show'])->name('incoming.show');
        Route::patch('incoming-correspondence/{record}', [IncomingCorrespondenceController::class, 'update'])->name('incoming.update');
        Route::post('incoming-correspondence/{record}/register', [IncomingCorrespondenceController::class, 'register'])->name('incoming.register');
        Route::get('numbering-series', [NumberingController::class, 'index'])->name('numbering.index');
        Route::get('numbering-series/registers', [NumberingController::class, 'registers'])->name('numbering.registers');
        Route::post('numbering-series/preview', [NumberingController::class, 'preview'])->name('numbering.preview');
        Route::post('numbering-series', [NumberingController::class, 'store'])->name('numbering.store');
        Route::patch('numbering-series/{series}', [NumberingController::class, 'update'])->name('numbering.update');
        Route::get('numbering-series/{series}/history', [NumberingController::class, 'history'])->name('numbering.history');
        Route::get('document-master-data/classifications', [DocumentMasterDataController::class, 'classifications'])->name('master-data.classifications');
        Route::get('document-master-data/{kind}', [DocumentMasterDataController::class, 'index'])->whereIn('kind', ['categories', 'types', 'registers'])->name('master-data.index');
        Route::post('document-master-data/{kind}', [DocumentMasterDataController::class, 'store'])->whereIn('kind', ['categories', 'types', 'registers'])->name('master-data.store');
        Route::patch('document-master-data/{kind}/{record}', [DocumentMasterDataController::class, 'update'])->whereIn('kind', ['categories', 'types', 'registers'])->name('master-data.update');
        Route::get('organization-contexts', [MembershipController::class, 'operationalContexts'])->name('organization-contexts');
        Route::get('membership-units', [MembershipController::class, 'units'])->name('membership-units');
        Route::get('membership-users', [MembershipController::class, 'users'])->name('membership-users');
        Route::get('memberships', [MembershipController::class, 'index'])->name('memberships.index');
        Route::post('memberships', [MembershipController::class, 'store'])->name('memberships.store');
        Route::patch('memberships/{membership}', [MembershipController::class, 'update'])->name('memberships.update');
        Route::delete('memberships/{membership}', [MembershipController::class, 'destroy'])->name('memberships.destroy');
        Route::get('authorization/contexts', [AuthorizationContextController::class, 'index'])->name('authorization.contexts');
        Route::get('role-assignment-users', [AuthorizationContextController::class, 'users'])->name('assignment-users');
        Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions');
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::get('role-assignments', [RoleAssignmentController::class, 'index'])->name('assignments.index');
        Route::post('role-assignments', [RoleAssignmentController::class, 'store'])->name('assignments.store');
        Route::delete('role-assignments/{assignment}', [RoleAssignmentController::class, 'destroy'])->name('assignments.destroy');
    });
});
