<?php

namespace App\Models;

use Database\Factories\AuthorizationScopeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_type', 'company_id', 'site_id'])]
class AuthorizationScope extends Model
{
    /** @use HasFactory<AuthorizationScopeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (AuthorizationScope $scope): void {
            if ($scope->exists && $scope->isDirty(['scope_type', 'company_id', 'site_id', 'scope_key'])) {
                throw ValidationException::withMessages(['scope_type' => 'rbacScopeImmutable']);
            }
            $scope->validateShape();
            $scope->scope_key = $scope->canonicalKey();
        });
    }

    public static function context(string $type, ?int $companyId = null, ?int $siteId = null): self
    {
        $scope = new self(['scope_type' => $type, 'company_id' => $companyId, 'site_id' => $siteId]);
        $scope->validateShape();
        $scope->scope_key = $scope->canonicalKey();

        return self::query()->where('scope_key', $scope->scope_key)->first() ?? $scope;
    }

    public function persist(): self
    {
        if (! $this->exists) {
            $this->save();
        }

        return $this;
    }

    public function validateShape(): void
    {
        $valid = match ($this->scope_type) {
            'global' => $this->company_id === null && $this->site_id === null,
            'company' => $this->company_id !== null && $this->site_id === null && Company::query()->whereKey($this->company_id)->exists(),
            'site' => $this->company_id !== null && $this->site_id !== null && Site::query()->whereKey($this->site_id)->where('company_id', $this->company_id)->exists(),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['scope_type' => 'rbacInvalidScope']);
        }
    }

    public function canonicalKey(): string
    {
        return match ($this->scope_type) {
            'global' => 'global', 'company' => 'company:'.$this->company_id, 'site' => 'site:'.$this->site_id,
        };
    }

    public function isActive(): bool
    {
        if ($this->scope_type === 'global') {
            return $this->company_id === null && $this->site_id === null;
        }
        if (! in_array($this->scope_type, ['company', 'site'], true) || ($this->scope_type === 'company' && $this->site_id !== null)) {
            return false;
        }
        if (! Company::query()->whereKey($this->company_id)->where('active', true)->exists()) {
            return false;
        }

        return $this->scope_type === 'company' || Site::query()->whereKey($this->site_id)->where('company_id', $this->company_id)->where('active', true)->exists();
    }

    public function covers(self $target): bool
    {
        if (! $this->isActive() || ! $target->isActive()) {
            return false;
        }

        return $this->scope_type === 'global'
            || ($this->scope_type === 'company' && $target->scope_type !== 'global' && $this->company_id === $target->company_id)
            || ($this->scope_type === 'site' && $target->scope_type === 'site' && $this->company_id === $target->company_id && $this->site_id === $target->site_id);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['company_id' => 'integer', 'site_id' => 'integer'];
    }
}
