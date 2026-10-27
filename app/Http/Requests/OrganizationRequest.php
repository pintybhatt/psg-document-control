<?php

namespace App\Http\Requests;

use App\Authorization\AccessControl;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(AccessControl::class)->isSystemAdministrator($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kind = $this->route('kind');
        $listing = $this->isMethod('GET');
        $creating = $this->isMethod('POST');
        $rules = [
            'company_id' => ($listing || $creating) && $kind !== 'companies' ? ['required', 'integer', 'exists:companies,id'] : ['missing'],
            'site_id' => ($listing || $creating) && $kind === 'departments' ? ['required', 'integer', 'exists:sites,id'] : ['missing'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
        if (! $listing) {
            $rules += ['code' => $creating ? ['required', 'string'] : ['missing'], 'name_en' => ['sometimes', 'string'], 'name_lo' => ['sometimes', 'string'], 'active' => ['sometimes', 'boolean']];
        }
        foreach (['id', 'actor_id', 'scope_id', 'created_by', 'updated_by'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_fill_keys(['required', 'string', 'integer', 'min', 'max', 'exists', 'boolean', 'missing'], 'orgInvalid');
    }
}
