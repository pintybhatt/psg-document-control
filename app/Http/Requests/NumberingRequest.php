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
        if (in_array($this->route()->getActionMethod(), ['xpplOptions', 'xpplReview', 'xpplConfirm', 'xpplActivate'], true)) {
            return $rules + ['series_id' => ['sometimes', 'integer'], 'department_id' => ['sometimes', 'integer'], 'type_id' => ['sometimes', 'integer'],
                'format' => ['sometimes', Rule::in(['xppl_controlled', 'xppl_letter'])], 'token' => ['sometimes', 'string'],
                'next_sequence' => ['sometimes', 'integer'], 'confirm' => ['sometimes', 'accepted']];
        }
        foreach (['scope_id', 'created_by', 'issued_by', 'counter', 'value', 'sequence', 'version', 'effective_at', 'number', 'configuration_id', 'period', 'series_id', 'record_type', 'record_key'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if ($this->isMethod('GET')) {
            return $rules;
        }
        if ($this->route()->getName() === 'api.v1.numbering.preview' && $this->has('series_id')) {
            $rules['series_id'] = ['required', 'integer', 'min:1'];

            return $rules + ['prefix' => ['prohibited'], 'padding' => ['prohibited'], 'year' => ['prohibited']];
        }
        $rules += NumberingFormat::rules();
        if ($this->route()->getName() === 'api.v1.numbering.preview') {
            return $rules + ['year' => ['required', 'integer', 'between:2000,9999']];
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
