<?php

namespace App\Http\Requests;

use App\Authorization\DocumentResourceType;
use App\Authorization\ManageDocumentMasterData;
use Illuminate\Validation\Rule;

class DocumentMasterDataRequest extends RbacScopeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['scope_id', 'created_by', 'updated_by', 'actor_id', 'classification', 'confidentiality'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if ($this->isMethod('GET')) {
            return $rules;
        }
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $kind = $this->route('kind');
        $scope = $this->scopeForValidation();
        $table = app(ManageDocumentMasterData::class)->query($kind, $scope)->getModel()->getTable();
        $rules += [
            'active' => [$required, 'boolean'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_lo' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
        if ($kind === 'registers') {
            $rules['register_key'] = $creating
                ? ['required', Rule::enum(DocumentResourceType::class), Rule::unique($table)->where('scope_id', $scope->id)] : ['prohibited'];
            foreach (['code', 'name_en', 'name_lo', 'category_id', 'register_ids'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        } else {
            $rules += [
                'code' => $creating ? ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_-]{1,63}$/', Rule::unique($table)->where('scope_id', $scope->id)] : ['prohibited'],
                'name_en' => [...($creating ? [] : ['sometimes']), 'required', 'string', 'max:255'],
                'name_lo' => [...($creating ? [] : ['sometimes']), 'required', 'string', 'max:255'],
                'register_key' => ['prohibited'],
            ];
            if ($kind === 'types') {
                $rules['category_id'] = $creating ? ['required', 'integer', Rule::exists('document_categories', 'id')->where('scope_id', $scope->id)] : ['prohibited'];
                $rules['register_ids'] = [$required, 'array', 'min:1', 'max:3'];
                $rules['register_ids.*'] = ['integer', 'distinct', Rule::exists('register_configurations', 'id')->where('scope_id', $scope->id)];
            } else {
                $rules['category_id'] = ['prohibited'];
                $rules['register_ids'] = ['prohibited'];
            }
        }

        return $rules;
    }
}
