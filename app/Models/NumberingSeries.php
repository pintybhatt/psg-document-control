<?php

namespace App\Models;

use App\Authorization\NumberingPurpose;
use App\Authorization\NumberingResetPolicy;
use Database\Factories\NumberingSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'register_id', 'purpose', 'reset_policy'])]
class NumberingSeries extends ScopedDocumentMaster
{
    /** @use HasFactory<NumberingSeriesFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (NumberingSeries $series): void {
            if ($series->isDirty(['register_id', 'purpose', 'reset_policy', 'created_by'])) {
                throw ValidationException::withMessages(['series' => 'numberingIdentityImmutable']);
            }
        });
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(RegisterConfiguration::class, 'register_id');
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(NumberingConfiguration::class, 'series_id');
    }

    public function currentConfiguration(): HasOne
    {
        return $this->hasOne(NumberingConfiguration::class, 'series_id')->ofMany('version', 'max');
    }

    protected function casts(): array
    {
        return ['purpose' => NumberingPurpose::class, 'reset_policy' => NumberingResetPolicy::class];
    }
}
