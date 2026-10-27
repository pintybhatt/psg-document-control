<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ManagementReportRequest extends DocumentSearchRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        if ($this->input('view') === 'revisions') {
            $rules['register'] = ['nullable', Rule::in(['controlled'])];
        }

        return $rules + [
            'view' => ['nullable', Rule::in(['documents', 'revisions', 'physical'])],
            'metric' => ['nullable', Rule::in(['registered', 'open', 'closed', 'in_progress', 'pending_response', 'due_soon', 'overdue', 'dispatched', 'delivered', 'acknowledged', 'review_soon', 'review_overdue', 'checkout_overdue', 'inventory_discrepancy'])],
            'all_departments' => ['sometimes', 'boolean'], 'all_sites' => ['sometimes', 'boolean'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])], 'language' => ['nullable', Rule::in(['en', 'lo'])],
        ];
    }

    public function messages(): array
    {
        return array_map(fn (string $message): string => 'reportValidation', parent::messages());
    }
}
