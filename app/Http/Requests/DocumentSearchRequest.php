<?php

namespace App\Http\Requests;

use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function documentScope(): DocumentScope
    {
        $scope = new DocumentScope($this->integer('company_id'), $this->integer('site_id'), $this->filled('department_id') ? $this->integer('department_id') : null);
        app(ManageIncomingCorrespondence::class)->authorizeContext($this->user(), $scope);

        return $scope;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
            'register' => ['nullable', Rule::in(['incoming', 'outgoing', 'controlled'])],
            'search' => ['nullable', 'string', 'max:200'], 'exact' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'integer', 'min:1'], 'type_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['draft', 'registered', 'received', 'assigned', 'in_progress', 'responded', 'closed', 'dispatched', 'delivered', 'current', 'superseded', 'obsolete', 'archived'])],
            'classification' => ['nullable', Rule::in(['normal', 'confidential', 'highly_confidential'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
            'revision' => ['nullable', 'regex:/^[0-9]{1,6}$/D'], 'revision_state' => ['nullable', Rule::in(['draft', 'current', 'superseded'])],
            'owner' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'number', 'date'])],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'integer', 'min', 'max', 'string', 'in', 'boolean', 'regex', 'date_format', 'after_or_equal'], 'searchValidation');
    }
}
