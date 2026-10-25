<?php

namespace App\Authorization;

interface FormalNumberedRegistration
{
    public function numberingScopeId(): int;

    public function numberingRegisterId(): int;

    public function isFormallyRegistered(): bool;

    public function registeredNumber(NumberingPurpose $purpose): ?string;
}
