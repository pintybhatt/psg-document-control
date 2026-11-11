<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Authorization\RbacMutation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'preferred_language'])]
#[Hidden(['password', 'remember_token', 'session_version', 'profile_photo_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->session_version = Str::random(40);
        });

        static::updating(function (User $user): void {
            if (($user->isDirty('active') && ! $user->active) || $user->isDirty('password')) {
                $user->session_version = Str::random(40);
                $user->remember_token = null;
            }
        });
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    /** Same-origin URL for the private photo; the file name changes on every upload, so it doubles as a cache key. */
    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo_path === null ? null
            : route('profile.photo.show', ['v' => pathinfo($this->profile_photo_path, PATHINFO_FILENAME)], false);
    }

    public function deactivate(): void
    {
        $this->forceFill(['active' => false])->save();
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('active') && ! $this->active) {
            return app(RbacMutation::class)->run(fn (): bool => parent::save($options));
        }

        return parent::save($options);
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<ConfidentialityClearance, $this> */
    public function clearances(): HasMany
    {
        return $this->hasMany(ConfidentialityClearance::class);
    }

    /** @return HasMany<DocumentAccessGrant, $this> */
    public function documentAccessGrants(): HasMany
    {
        return $this->hasMany(DocumentAccessGrant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
