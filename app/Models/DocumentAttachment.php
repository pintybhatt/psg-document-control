<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['id', 'profile_id', 'revision_id'])]
class DocumentAttachment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public function versions(): HasMany
    {
        return $this->hasMany(AttachmentVersion::class, 'attachment_id')->orderBy('version');
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
