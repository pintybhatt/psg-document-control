<?php

namespace App\Http\Requests;

use App\Authorization\TemplateCatalogue;

class TemplateCatalogueRequest extends RbacScopeRequest
{
    public function rules(): array
    {
        return parent::rules() + ($this->isMethod('POST')
            ? ($this->route()->getActionMethod() === 'retire' ? ['reason' => ['required', 'string', 'max:2000']] : TemplateCatalogue::creationRules()) : []);
    }

    /** @return array<string, mixed> */
    public function catalogueData(): array
    {
        return $this->except(['scope_type', 'company_id', 'site_id', 'q', 'page', 'per_page']);
    }
}
