<?php

namespace App\Http\Controllers;

use App\Authorization\Confidentiality;
use App\Authorization\ConfidentialityAdministration;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\DocumentStorageTarget;
use App\Authorization\ManageDocumentSecurity;
use App\Authorization\RbacMutation;
use App\Authorization\SearchDocuments;
use App\Http\Requests\ConfidentialityAdministrationRequest;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ConfidentialityAdministrationController extends Controller
{
    public function __construct(private ConfidentialityAdministration $administration, private ManageDocumentSecurity $security, private RbacMutation $mutation, private DocumentStorageTarget $targets) {}

    public function contexts(ConfidentialityAdministrationRequest $request): JsonResponse
    {
        $contexts = $this->administration->contexts($request->user())->filter(fn (DocumentScope $scope): bool => $scope->companyId === $request->integer('company_id') && $scope->siteId === $request->integer('site_id'));
        $departments = Department::query()->whereIn('id', $contexts->pluck('departmentId')->filter())->get()->keyBy('id');

        return $this->response(['data' => $contexts->map(fn (DocumentScope $scope): array => [
            'department_id' => $scope->departmentId, 'department' => $departments->get($scope->departmentId)?->only(['name_en', 'name_lo']),
            'permissions' => $this->administration->permissions($request->user(), $scope),
            'can_manage_documents' => $this->administration->canManageDocuments($request->user(), $scope),
        ])->values()]);
    }

    public function users(ConfidentialityAdministrationRequest $request): JsonResponse
    {
        $users = $this->administration->users($request->user(), $request->documentScope());
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $users->where(fn (Builder $query): Builder => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return $this->page($users->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 10), ['id', 'name', 'email']));
    }

    public function clearances(ConfidentialityAdministrationRequest $request): JsonResponse
    {
        $query = $this->administration->clearances($request->user(), $request->documentScope())->with('user');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $this->grantsPage($query->orderByDesc('id')->paginate($request->integer('per_page', 10)), $request);
    }

    public function grantClearance(ConfidentialityAdministrationRequest $request): JsonResponse
    {
        $this->mutation->run(function () use ($request): void {
            $scope = $request->documentScope();
            $classification = Confidentiality::from($request->validated('classification'));
            $this->administration->authorize($request->user(), $scope, $classification->managementPermission());
            $this->security->grantClearance($request->user(), $this->targetUser($request), $scope, $classification, $request->expiry());
        });

        return $this->response(['message' => 'confidentialitySaved'], 201);
    }

    public function revokeClearance(ConfidentialityAdministrationRequest $request, string $clearance): JsonResponse
    {
        $this->mutation->run(function () use ($request, $clearance): void {
            $record = $this->administration->clearances($request->user(), $request->documentScope())->whereKey($clearance)->firstOrFail();
            $this->security->revoke($request->user(), $record);
        });

        return $this->response(['message' => 'confidentialityRevoked']);
    }

    public function documents(ConfidentialityAdministrationRequest $request, SearchDocuments $search): JsonResponse
    {
        $scope = $request->documentScope();
        $this->administration->authorize($request->user(), $scope, 'document-access.manage');
        $page = $search->query($request->user(), $scope, $request->safe()->only(['search']))
            ->whereIn('profile_id', DocumentSecurityProfile::query()->where('restricted', true)->select('id'))->paginate($request->integer('per_page', 10));
        $search->auditResults($request->user(), $scope, $page->getCollection()->pluck('profile_id')->all());
        $page->setCollection($page->getCollection()->map(fn (object $row): array => [
            'id' => $row->id, 'register' => $row->register, 'number' => $row->number, 'title_en' => $row->title_en, 'title_lo' => $row->title_lo,
        ]));

        return $this->page($page);
    }

    public function grants(ConfidentialityAdministrationRequest $request, string $type, string $document): JsonResponse
    {
        $profile = $this->profile($request, $type, $document);

        return $this->grantsPage($profile->accessGrants()->with('user')->orderByDesc('id')->paginate($request->integer('per_page', 10)), $request);
    }

    public function grantDocument(ConfidentialityAdministrationRequest $request, string $type, string $document): JsonResponse
    {
        $this->mutation->run(function () use ($request, $type, $document): void {
            $profile = $this->profile($request, $type, $document);
            $target = $this->targetUser($request);
            foreach ($request->validated('operations') as $operation) {
                $this->security->allowDocument($request->user(), $target, DocumentSecurityDescriptor::fromProfile($profile), DocumentOperation::from($operation), $request->expiry());
            }
        });

        return $this->response(['message' => 'confidentialitySaved'], 201);
    }

    public function revokeDocument(ConfidentialityAdministrationRequest $request, string $type, string $document, string $grant): JsonResponse
    {
        $this->mutation->run(function () use ($request, $type, $document, $grant): void {
            $record = $this->profile($request, $type, $document)->accessGrants()->whereKey($grant)->firstOrFail();
            $this->security->revoke($request->user(), $record);
        });

        return $this->response(['message' => 'confidentialityRevoked']);
    }

    private function profile(ConfidentialityAdministrationRequest $request, string $type, string $document): DocumentSecurityProfile
    {
        $scope = $request->documentScope();
        $this->administration->authorize($request->user(), $scope, 'document-access.manage');
        $profile = $this->targets->find($request->user(), $scope, $type, $document, 'document-access.manage')->profile;
        abort_unless($profile->restricted, 404);

        return $profile;
    }

    private function targetUser(ConfidentialityAdministrationRequest $request): User
    {
        abort_if($request->integer('user_id') === $request->user()->id, 403, 'documentSecurityForbidden');

        return $this->administration->users($request->user(), $request->documentScope())->whereKey($request->integer('user_id'))->firstOrFail();
    }

    private function grantsPage(LengthAwarePaginator $page, ConfidentialityAdministrationRequest $request): JsonResponse
    {
        $page->setCollection($page->getCollection()->map(fn (ConfidentialityClearance|DocumentAccessGrant $record): array => [
            'id' => $record->id, 'user' => $record->user->only(['id', 'name', 'email']),
            'classification' => $record instanceof ConfidentialityClearance ? $record->classification->value : null,
            'operation' => $record instanceof DocumentAccessGrant ? $record->operation->value : null,
            'status' => $record->revoked_at !== null ? 'revoked' : (! $record->active ? 'inactive' : ($record->expires_at?->isPast() ? 'expired' : 'active')),
            'expires_at' => $record->expires_at?->toISOString(), 'revoked_at' => $record->revoked_at?->toISOString(),
            'revocable' => $record->revoked_at === null && $record->user_id !== $request->user()->id,
        ]));

        return $this->page($page);
    }

    private function page(LengthAwarePaginator $page): JsonResponse
    {
        return $this->response(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()]]);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
