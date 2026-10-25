<?php

namespace App\Authorization;

enum DocumentOperation: string
{
    case Metadata = 'metadata';
    case Preview = 'preview';
    case Download = 'download';

    public function permission(): string
    {
        return match ($this) {
            self::Metadata => 'documents.metadata.view',
            self::Preview => 'documents.content.preview',
            self::Download => 'documents.attachments.download',
        };
    }

    public function labelKey(): string
    {
        return 'documentOperation-'.$this->value;
    }
}
