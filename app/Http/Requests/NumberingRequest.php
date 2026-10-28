<?php

namespace App\Http\Requests;

use App\Authorization\NumberingFormat;
use App\Authorization\NumberingPurpose;
use App\Authorization\NumberingResetPolicy;
use Illuminate\Validation\Rule;

class NumberingRequest extends RbacScopeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['scope_id', 'created_by', 'issued_by', 'counter', 'value', 'sequence', 'version', 'effective_at', 'number', 'configuration_id', 'period', 'series_id', 'record_type', 'record_key'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if ($this->isMethod('GET')) {
            return $rules;
        }
        $rules += NumberingFormat::rules();
        if ($this->route()->getName() === 'api.v1.numbering.preview') {
            return $rules + ['year' => ['required', 'integer', 'between:2000,9999'], 'reset_policy' => ['sometimes', Rule::enum(NumberingResetPolicy::class)]];
        }
        $rules['year'] = ['prohibited'];
        $rules += ['active' => ['required', 'boolean'], 'change_reason' => ['nullable', 'string', 'max:500']];
        if ($this->isMethod('PATCH')) {
            return $rules + ['register_id' => ['prohibited'], 'purpose' => ['prohibited'], 'reset_policy' => ['prohibited']];
        }
        $scope = $this->scopeForValidation();

        return $rules + [
            'register_id' => ['required', 'integer', Rule::exists('register_configurations', 'id')->where('scope_id', $scope->id)->where('active', true)],
            'purpose' => ['required', Rule::enum(NumberingPurpose::class)],
            'reset_policy' => ['required', Rule::enum(NumberingResetPolicy::class)],
        ];
    }
}
