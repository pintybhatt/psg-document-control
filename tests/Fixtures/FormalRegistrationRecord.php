<?php

namespace Tests\Fixtures;

use App\Authorization\FormalNumberedRegistration;
use App\Authorization\NumberingPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FormalRegistrationRecord extends Model implements FormalNumberedRegistration
{
    protected $table = 'test_formal_numbering_records';

    protected $guarded = [];

    public static function createTestTable(): void
    {
        if (! Schema::hasTable('test_formal_numbering_records')) {
            Schema::create('test_formal_numbering_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scope_id');
                $table->unsignedBigInteger('register_id');
                $table->string('state');
                $table->string('internal_number')->nullable()->unique();
                $table->string('official_number')->nullable()->unique();
                $table->string('external_reference')->nullable();
                $table->timestamps();
            });
        }
    }

    public function numberingScopeId(): int
    {
        return (int) $this->scope_id;
    }

    public function numberingRegisterId(): int
    {
        return (int) $this->register_id;
    }

    public function isFormallyRegistered(): bool
    {
        return $this->state === 'registered';
    }

    public function registeredNumber(NumberingPurpose $purpose): ?string
    {
        return $purpose === NumberingPurpose::InternalRegister ? $this->internal_number : $this->official_number;
    }
}
