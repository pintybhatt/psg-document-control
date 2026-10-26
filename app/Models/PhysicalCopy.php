<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'profile_id', 'revision_id', 'site_id', 'identifier', 'kind', 'location_id', 'status', 'custodian_id', 'checkout_date', 'expected_return_date', 'lock_version'])]
class PhysicalCopy extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'location_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function overdue(): bool
    {
        return $this->status === 'checked_out' && $this->expected_return_date->isBefore(today());
    }

    protected function casts(): array
    {
        return ['checkout_date' => 'date', 'expected_return_date' => 'date', 'lock_version' => 'integer'];
    }
}
