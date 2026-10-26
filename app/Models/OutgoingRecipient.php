<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['correspondence_id', 'kind', 'organization', 'contact_name', 'email', 'phone', 'address'])]
class OutgoingRecipient extends Model
{
    public function delivery(): HasOne
    {
        return $this->hasOne(OutgoingDelivery::class, 'recipient_id');
    }

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(OutgoingCorrespondence::class, 'correspondence_id');
    }
}
