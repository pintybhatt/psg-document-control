<?php

use App\Http\Controllers\AdministrationHistoryController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\AuthorizationContextController;
use App\Http\Controllers\ControlledDocumentController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentMasterDataController;
use App\Http\Controllers\DocumentSearchController;
use App\Http\Controllers\DocumentStorageController;
use App\Http\Controllers\IncomingCorrespondenceController;
use App\Http\Controllers\IncomingOperationController;
use App\Http\Controllers\ManagementReportController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\NumberingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OutgoingCorrespondenceController;
use App\Http\Controllers\OutgoingOperationController;
use App\Http\Controllers\PhysicalCustodyController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Http\Middleware\AuditDocumentStorageRequests;
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
        Route::get('document-search/lookups', [DocumentSearchController::class, 'lookups'])->name('document-search.lookups');
        Route::get('document-search', [DocumentSearchController::class, 'index'])->name('document-search');
        Route::get('reports/lookups', [ManagementReportController::class, 'lookups'])->name('reports.lookups');
        Route::get('reports/export', [ManagementReportController::class, 'export'])->name('reports.export');
        Route::get('reports', [ManagementReportController::class, 'index'])->name('reports.index');
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('controlled-documents/contexts', [ControlledDocumentController::class, 'contexts'])->name('controlled.contexts');
        Route::get('controlled-documents/lookups', [ControlledDocumentController::class, 'lookups'])->name('controlled.lookups');
        Route::get('controlled-documents', [ControlledDocumentController::class, 'index'])->name('controlled.index');
        Route::post('controlled-documents', [ControlledDocumentController::class, 'store'])->name('controlled.store');
        Route::get('controlled-documents/{record}', [ControlledDocumentController::class, 'show'])->name('controlled.show');
        Route::patch('controlled-documents/{record}', [ControlledDocumentController::class, 'update'])->name('controlled.update');
        Route::post('controlled-documents/{record}/register', [ControlledDocumentController::class, 'register'])->name('controlled.register');
        Route::post('controlled-documents/{record}/revisions', [ControlledDocumentController::class, 'revise'])->name('controlled.revise');
        Route::post('controlled-documents/{record}/release', [ControlledDocumentController::class, 'release'])->name('controlled.release');
        Route::post('controlled-documents/{record}/status', [ControlledDocumentController::class, 'changeStatus'])->name('controlled.changeStatus');
        Route::get('outgoing-correspondence/{record}/operation-options', [OutgoingOperationController::class, 'options'])->name('outgoing.operation-options');
        Route::post('outgoing-correspondence/{record}/operations', [OutgoingOperationController::class, 'operate'])->name('outgoing.operations');
        Route::get('outgoing-correspondence/contexts', [OutgoingCorrespondenceController::class, 'contexts'])->name('outgoing.contexts');
        Route::get('outgoing-correspondence/export', [OutgoingCorrespondenceController::class, 'export'])->name('outgoing.export');
        Route::get('outgoing-correspondence/{record}/pdf', [OutgoingCorrespondenceController::class, 'detailPdf'])->name('outgoing.detail-pdf');
        Route::get('outgoing-correspondence/lookups', [OutgoingCorrespondenceController::class, 'lookups'])->name('outgoing.lookups');
        Route::get('outgoing-correspondence', [OutgoingCorrespondenceController::class, 'index'])->name('outgoing.index');
        Route::post('outgoing-correspondence', [OutgoingCorrespondenceController::class, 'store'])->name('outgoing.store');
        Route::get('outgoing-correspondence/{record}', [OutgoingCorrespondenceController::class, 'show'])->name('outgoing.show');
        Route::patch('outgoing-correspondence/{record}', [OutgoingCorrespondenceController::class, 'update'])->name('outgoing.update');
        Route::post('outgoing-correspondence/{record}/register', [OutgoingCorrespondenceController::class, 'register'])->name('outgoing.register');
        Route::get('incoming-correspondence/contexts', [IncomingCorrespondenceController::class, 'contexts'])->name('incoming.contexts');
        Route::get('incoming-correspondence/lookups', [IncomingCorrespondenceController::class, 'lookups'])->name('incoming.lookups');
        Route::get('incoming-correspondence/{record}/pdf', [IncomingCorrespondenceController::class, 'detailPdf'])->name('incoming.detail-pdf');
        Route::get('incoming-correspondence/export', [IncomingCorrespondenceController::class, 'export'])->name('incoming.export');
        Route::get('incoming-correspondence', [IncomingCorrespondenceController::class, 'index'])->name('incoming.index');
        Route::post('incoming-correspondence', [IncomingCorrespondenceController::class, 'store'])->name('incoming.store');
        Route::get('incoming-correspondence/{record}', [IncomingCorrespondenceController::class, 'show'])->name('incoming.show');
        Route::patch('incoming-correspondence/{record}', [IncomingCorrespondenceController::class, 'update'])->name('incoming.update');
        Route::post('incoming-correspondence/{record}/register', [IncomingCorrespondenceController::class, 'register'])->name('incoming.register');
        Route::get('incoming-correspondence/{record}/operation-options', [IncomingOperationController::class, 'options'])->name('incoming.operation-options');
        Route::post('incoming-correspondence/{record}/operations', [IncomingOperationController::class, 'operate'])->name('incoming.operations');
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
        Route::get('organization/{kind}', [OrganizationController::class, 'index'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.index');
        Route::post('organization/{kind}', [OrganizationController::class, 'store'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.store');
        Route::patch('organization/{kind}/{record}', [OrganizationController::class, 'update'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.update');
        Route::get('administration/history', AdministrationHistoryController::class)->name('administration.history');
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

Route::prefix('api/v1/document-storage/{type}/{record}')->whereIn('type', ['incoming', 'outgoing', 'controlled'])
    ->middleware([AuditDocumentStorageRequests::class, 'auth:web', EnsureActiveUserSession::class])->name('api.v1.storage.')->group(function (): void {
        Route::get('versions/{version}/preview', [DocumentStorageController::class, 'preview'])->whereUuid('version')->name('attachments.preview');
        Route::get('versions/{version}/download', [DocumentStorageController::class, 'download'])->whereUuid('version')->name('attachments.download');
        Route::get('copies', [PhysicalCustodyController::class, 'copies'])->name('copies.index');
        Route::post('copies', [PhysicalCustodyController::class, 'createCopy'])->name('copies.store');
        Route::post('copies/{copy}/events', [PhysicalCustodyController::class, 'change'])->whereUuid('copy')->name('copies.change');
        Route::post('locations', [PhysicalCustodyController::class, 'createLocation'])->name('locations.store');
        Route::get('/', [DocumentStorageController::class, 'index'])->name('index');
        Route::get('attachments', [DocumentStorageController::class, 'index'])->name('attachments.index');
        Route::post('attachments', [DocumentStorageController::class, 'store'])->name('attachments.store');
    });
