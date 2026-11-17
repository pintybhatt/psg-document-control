<?php

use App\Http\Controllers\AdministrationHistoryController;
use App\Http\Controllers\ApprovalWorkflowController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\AuthorizationContextController;
use App\Http\Controllers\ConfidentialityAdministrationController;
use App\Http\Controllers\ControlledDocumentController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentApprovalController;
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
use App\Http\Controllers\ProfilePasswordController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TemplateCatalogueController;
use App\Http\Controllers\UserManagementController;
use App\Http\Middleware\AuditDocumentStorageRequests;
use App\Http\Middleware\EnsureActiveUserSession;
use App\Http\Middleware\EnsureAdministrationAccess;
use App\Http\Middleware\EnsureDevelopmentEnvironment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth('web')->check()
        ? redirect()->route('dcms.dashboard')
        : redirect()->route('login');
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
    $middleware = ['auth:web', EnsureActiveUserSession::class];
    if ($page === 'administration') {
        $middleware[] = EnsureAdministrationAccess::class;
    }
    Route::view($path, 'app', ['page' => $page, 'pages' => $pages])
        ->middleware($middleware)->name('dcms.'.$page);
}

Route::view('/profile', 'app', ['page' => 'profile', 'pages' => $pages])
    ->middleware(['auth:web', EnsureActiveUserSession::class])->name('dcms.profile');

Route::get('/dev/ui-components', function () use ($pages): View {
    return view('app', ['page' => 'ui-preview', 'pages' => $pages]);
})->middleware([EnsureDevelopmentEnvironment::class, 'auth:web', EnsureActiveUserSession::class])->name('dcms.ui-preview');

Route::middleware(['auth:web', EnsureActiveUserSession::class])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/api/v1/user', CurrentUserController::class)->name('api.v1.user');
    Route::get('/profile/photo', [ProfilePhotoController::class, 'show'])->name('profile.photo.show');
    Route::post('/api/v1/profile/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:20,1')->name('api.v1.profile.photo.store');
    Route::delete('/api/v1/profile/photo', [ProfilePhotoController::class, 'destroy'])->name('api.v1.profile.photo.destroy');
    Route::put('/api/v1/profile/password', [ProfilePasswordController::class, 'update'])->middleware('throttle:6,1')->name('api.v1.profile.password.update');
    Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
        Route::prefix('confidentiality')->name('confidentiality.')->controller(ConfidentialityAdministrationController::class)->group(function (): void {
            Route::get('contexts', 'contexts')->name('contexts');
            Route::get('users', 'users')->name('users');
            Route::get('clearances', 'clearances')->name('clearances.index');
            Route::post('clearances', 'grantClearance')->name('clearances.store');
            Route::delete('clearances/{clearance}', 'revokeClearance')->whereNumber('clearance')->name('clearances.revoke');
            Route::get('documents', 'documents')->name('documents');
            Route::get('documents/{type}/{document}/grants', 'grants')->whereIn('type', ['incoming', 'outgoing', 'controlled'])->whereNumber('document')->name('grants.index');
            Route::post('documents/{type}/{document}/grants', 'grantDocument')->whereIn('type', ['incoming', 'outgoing', 'controlled'])->whereNumber('document')->name('grants.store');
            Route::delete('documents/{type}/{document}/grants/{grant}', 'revokeDocument')->whereIn('type', ['incoming', 'outgoing', 'controlled'])->whereNumber('document')->whereNumber('grant')->name('grants.revoke');
        });
        Route::get('document-approvals/{type}/{record}', [DocumentApprovalController::class, 'show'])->name('document-approvals.show');
        Route::post('document-approvals/{type}/{record}', [DocumentApprovalController::class, 'store'])->name('document-approvals.store');
        Route::get('approval-workflows', [ApprovalWorkflowController::class, 'index'])->name('approval-workflows.index');
        Route::get('approval-workflows/roles', [ApprovalWorkflowController::class, 'roles'])->name('approval-workflows.roles');
        Route::post('approval-workflows/{register}', [ApprovalWorkflowController::class, 'store'])->name('approval-workflows.store');
        Route::get('approval-workflows/{register}/history', [ApprovalWorkflowController::class, 'history'])->name('approval-workflows.history');
        Route::get('document-templates', [TemplateCatalogueController::class, 'index'])->name('templates.index');
        Route::get('document-templates/sources', [TemplateCatalogueController::class, 'sources'])->name('templates.sources');
        Route::post('document-templates', [TemplateCatalogueController::class, 'store'])->name('templates.store');
        Route::get('document-templates/{template}', [TemplateCatalogueController::class, 'show'])->name('templates.show');
        Route::post('document-templates/{template}/publish', [TemplateCatalogueController::class, 'publish'])->name('templates.publish');
        Route::get('document-templates/{template}/download', [TemplateCatalogueController::class, 'download'])->middleware(AuditDocumentStorageRequests::class)->name('templates.download');
        Route::post('document-templates/{template}/retire', [TemplateCatalogueController::class, 'retire'])->name('templates.retire');
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
        Route::post('controlled-documents/{record}/revisions/{revision}/reviews', [ControlledDocumentController::class, 'recordReview'])->name('controlled.reviews.record');
        Route::post('controlled-documents/{record}/revisions/{revision}/approvals', [ControlledDocumentController::class, 'recordApproval'])->name('controlled.approvals.record');
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
        Route::get('numbering-series/xppl/options', [NumberingController::class, 'xpplOptions'])->name('numbering.xppl-options');
        Route::post('numbering-series/xppl/activate', [NumberingController::class, 'xpplActivate'])->name('numbering.xppl-activate');
        Route::post('numbering-series/xppl/review', [NumberingController::class, 'xpplReview'])->name('numbering.xppl-review');
        Route::post('numbering-series/xppl/confirm', [NumberingController::class, 'xpplConfirm'])->name('numbering.xppl-confirm');
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
        Route::get('user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::post('user-management', [UserManagementController::class, 'store'])->name('user-management.store');
        Route::get('user-management/{user}/access', [UserManagementController::class, 'access'])->name('user-management.access');
        Route::patch('user-management/{user}', [UserManagementController::class, 'update'])->name('user-management.update');
        Route::get('memberships', [MembershipController::class, 'index'])->name('memberships.index');
        Route::post('memberships', [MembershipController::class, 'store'])->name('memberships.store');
        Route::patch('memberships/{membership}', [MembershipController::class, 'update'])->name('memberships.update');
        Route::delete('memberships/{membership}', [MembershipController::class, 'destroy'])->name('memberships.destroy');
        Route::get('organization/{kind}', [OrganizationController::class, 'index'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.index');
        Route::post('organization/{kind}', [OrganizationController::class, 'store'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.store');
        Route::patch('organization/{kind}/{record}', [OrganizationController::class, 'update'])->whereIn('kind', ['companies', 'sites', 'departments'])->name('organization.update');
        Route::delete('organization/departments/{record}', [OrganizationController::class, 'destroy'])->name('organization.departments.destroy');
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
