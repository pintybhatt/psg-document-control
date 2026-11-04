<?php

namespace App\Http\Requests;

use App\Authorization\ConfidentialityAdministration;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfidentialityAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ConfidentialityAdministration::class)->contexts($this->user())->contains(fn (DocumentScope $scope): bool => $scope->companyId === $this->integer('company_id') && $scope->siteId === $this->integer('site_id')
            && ($this->routeIs('api.v1.confidentiality.contexts') || $scope->departmentId === ($this->filled('department_id') ? $this->integer('department_id') : null)));
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope($this->integer('company_id'), $this->integer('site_id'), $this->filled('department_id') ? $this->integer('department_id') : null);
    }

    public function expiry(): ?CarbonImmutable
    {
        return $this->filled('expires_at') ? CarbonImmutable::parse($this->validated('expires_at'))->utc() : null;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:200'], 'search' => ['nullable', 'string', 'max:200'],
            'user_id' => [$this->isMethod('POST') ? 'required' : 'nullable', 'integer', 'min:1'],
            'classification' => [$this->routeIs('api.v1.confidentiality.clearances.store') ? 'required' : 'nullable', Rule::in(['confidential', 'highly_confidential'])],
            'operations' => [$this->routeIs('api.v1.confidentiality.grants.store') ? 'required' : 'sometimes', 'array', 'min:1', 'max:3'],
            'operations.*' => ['required', 'distinct', Rule::enum(DocumentOperation::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'integer', 'min', 'max', 'string', 'in', 'enum', 'array', 'distinct', 'date', 'after'], 'confidentialityValidation');
    }
}
