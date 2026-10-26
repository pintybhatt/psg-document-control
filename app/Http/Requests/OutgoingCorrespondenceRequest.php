<?php

namespace App\Http\Requests;

use App\Authorization\DocumentScope;
use App\Authorization\ManageOutgoingCorrespondence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OutgoingCorrespondenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        Validator::make($this->only(['company_id', 'site_id', 'department_id']), [
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
        ], $this->messages())->validate();
        $permission = match ($this->route()->getActionMethod()) {
            'store', 'update' => 'outgoing.drafts.manage', 'register' => 'outgoing.register',
            'operate', 'options' => 'outgoing.operations.manage', default => null,
        };
        app(ManageOutgoingCorrespondence::class)->authorizeContext($this->user(), $this->documentScope(), $permission);

        return true;
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope($this->integer('company_id'), $this->integer('site_id'), $this->filled('department_id') ? $this->integer('department_id') : null);
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'integer', 'min', 'max', 'prohibited'], 'outgoingValidation');
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'site_id' => ['required', 'integer'], 'department_id' => ['nullable', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'language' => ['sometimes', Rule::in(['en', 'lo'])],
            'format' => ['sometimes', Rule::in(['xlsx', 'pdf'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:200']];
        if ($this->route()->getActionMethod() === 'register') {
            foreach (array_diff(array_keys($this->all()), ['company_id', 'site_id', 'department_id', 'page', 'per_page']) as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }
}
