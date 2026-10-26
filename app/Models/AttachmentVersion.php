<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['id', 'attachment_id', 'version', 'original_name', 'mime_type', 'size', 'sha256', 'reason', 'uploaded_by'])]
class AttachmentVersion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['storage' => 'storageImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }
}
