<?php

namespace App\Http\Requests;

class ApprovalWorkflowRequest extends RbacScopeRequest
{
    /** @return array<string, mixed> */
    public function workflowData(): array
    {
        return $this->except(['scope_type', 'company_id', 'site_id']);
    }
}
