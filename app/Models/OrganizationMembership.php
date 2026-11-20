<?php

namespace App\Models;

use App\Authorization\MembershipAccess;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id', 'active', 'all_departments'])]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (OrganizationMembership $membership): void {
            if ($membership->exists && ($membership->isDirty(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id', 'created_by'])
                || $membership->getRawOriginal('revoked_at') !== null)) {
                throw ValidationException::withMessages(['membership' => 'membershipImmutable']);
            }
            app(MembershipAccess::class)->validateUnit($membership);
        });
        static::deleting(function (OrganizationMembership $membership): void {
            throw ValidationException::withMessages(['membership' => 'membershipImmutable']);
        });
    }

    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::context($this->site_id === null ? 'company' : 'site', $this->company_id, $this->site_id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<MembershipAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(MembershipAudit::class, 'membership_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'all_departments' => 'boolean', 'revoked_at' => 'datetime', 'company_id' => 'integer', 'site_id' => 'integer', 'department_id' => 'integer'];
    }
}
