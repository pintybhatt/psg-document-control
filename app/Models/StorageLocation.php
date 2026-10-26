<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'site_id', 'room', 'cabinet', 'shelf', 'box', 'path_hash', 'created_by'])]
class StorageLocation extends Model
{
    public function label(): string
    {
        return implode(' → ', array_filter($this->only(['room', 'cabinet', 'shelf', 'box']), fn (?string $value): bool => $value !== null));
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
