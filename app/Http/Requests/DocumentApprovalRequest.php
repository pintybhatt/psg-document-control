<?php

namespace App\Http\Requests;

class DocumentApprovalRequest extends DocumentStorageRequest
{
    public function rules(): array
    {
        return ['company_id' => ['required', 'integer'], 'site_id' => ['required', 'integer'], 'department_id' => ['nullable', 'integer'], 'revision_id' => ['nullable', 'integer', 'min:1']];
    }

    /** @return array<string, mixed> */
    public function approvalData(): array
    {
        return $this->except(['company_id', 'site_id', 'department_id', 'revision_id']);
    }
}
