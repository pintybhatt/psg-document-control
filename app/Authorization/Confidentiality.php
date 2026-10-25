<?php

namespace App\Authorization;

enum Confidentiality: string
{
    case Normal = 'normal';
    case Confidential = 'confidential';
    case HighlyConfidential = 'highly_confidential';

    public function labelKey(): string
    {
        return 'confidentiality-'.$this->value;
    }

    public function descriptionKey(): string
    {
        return $this->labelKey().'-description';
    }

    public function managementPermission(): string
    {
        return match ($this) {
            self::Normal => 'document-access.manage',
            self::Confidential => 'clearances.confidential.manage',
            self::HighlyConfidential => 'clearances.highly-confidential.manage',
        };
    }
}
