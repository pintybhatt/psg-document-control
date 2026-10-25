<?php

namespace App\Authorization;

use App\Models\DocumentSecurityProfile;
use Illuminate\Validation\ValidationException;

final readonly class DocumentSecurityDescriptor
{
    private function __construct(public int $profileId, public string $resourceType, public string $resourceKey) {}

    /**
     * The profile must be bound to the resource loaded by the server, never hydrated from request attributes.
     * Only persisted identity is retained; every policy check reloads authoritative security attributes.
     */
    public static function fromProfile(DocumentSecurityProfile $profile): self
    {
        $stored = $profile->exists ? DocumentSecurityProfile::query()->find($profile->getKey()) : null;
        if (! $stored) {
            throw ValidationException::withMessages(['resource' => 'documentSecurityInvalid']);
        }
        $stored->documentScope();

        return new self($stored->id, $stored->getRawOriginal('resource_type'), $stored->resource_key);
    }

    public function resolve(): ?DocumentSecurityProfile
    {
        return DocumentSecurityProfile::query()->whereKey($this->profileId)->where('resource_type', $this->resourceType)->where('resource_key', $this->resourceKey)->first();
    }
}
