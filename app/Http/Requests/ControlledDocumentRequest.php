<?php

namespace App\Http\Requests;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ControlledDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Validator::make($this->only(['company_id', 'site_id', 'department_id']), [
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
        ], $this->messages())->validate();
        $permission = match ($this->route()->getActionMethod()) {
            'store', 'update', 'revise' => 'controlled.drafts.manage', 'register' => 'controlled.register', 'release' => 'controlled.release', 'changeStatus' => 'controlled.status.manage', default => null,
        };
        app(ManageControlledDocuments::class)->authorizeContext($this->user(), $this->documentScope(), $permission);

        return true;
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope($this->integer('company_id'), $this->integer('site_id'), $this->filled('department_id') ? $this->integer('department_id') : null);
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'integer', 'min', 'max', 'string', 'in'], 'controlledValidation');
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'site_id' => ['required', 'integer'], 'department_id' => ['nullable', 'integer']];
        if ($this->isMethod('GET')) {
            $rules += ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['draft', 'current', 'superseded', 'obsolete', 'archived'])], 'type_id' => ['nullable', 'integer']];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function documentData(): array
    {
        return $this->except(['company_id', 'site_id', 'department_id']);
    }
}
