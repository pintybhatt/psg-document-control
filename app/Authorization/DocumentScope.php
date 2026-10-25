<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Department;
use Illuminate\Validation\ValidationException;

final readonly class DocumentScope
{
    public function __construct(public int $companyId, public int $siteId, public ?int $departmentId = null)
    {
        $this->authorizationScope();
        if ($departmentId !== null && ! Department::query()->whereKey($departmentId)->where('site_id', $siteId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['scope' => 'documentSecurityInvalid']);
        }
    }

    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::context('site', $this->companyId, $this->siteId);
    }

    public function isActive(): bool
    {
        return $this->authorizationScope()->isActive() && ($this->departmentId === null
            || Department::query()->whereKey($this->departmentId)->where('site_id', $this->siteId)->where('company_id', $this->companyId)->where('active', true)->exists());
    }
}
