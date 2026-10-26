<?php

namespace App\Http\Requests;

use App\Authorization\DocumentScope;
use App\Authorization\DocumentStorageTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class DocumentStorageRequest extends FormRequest
{
    public function authorize(): bool
    {
        Validator::make($this->only(['company_id', 'site_id', 'department_id']), [
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
        ], $this->messages())->validate();
        app(DocumentStorageTarget::class)->find($this->user(), $this->documentScope(), $this->route('type'), $this->route('record'));

        return true;
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope($this->integer('company_id'), $this->integer('site_id'), $this->filled('department_id') ? $this->integer('department_id') : null);
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'site_id' => ['required', 'integer'], 'department_id' => ['nullable', 'integer'], 'revision_id' => ['nullable', 'integer', 'min:1']];
        if ($this->route()->getActionMethod() === 'store') {
            $rules += ['file' => ['required', 'file', 'max:'.config('document_storage.max_upload_kb')], 'reason' => ['required', 'string', 'max:2000'], 'attachment_id' => ['nullable', 'uuid'], 'expected_version' => ['required_with:attachment_id', 'nullable', 'integer', 'min:1']];
        }

        return $rules;
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'integer', 'min', 'max', 'file', 'uuid', 'string', 'required_with'], 'storageValidation');
    }
}
