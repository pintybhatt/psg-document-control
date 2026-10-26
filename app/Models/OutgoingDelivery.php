<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recipient_id', 'correspondence_id', 'delivery_date', 'acknowledged_by', 'acknowledgment_date', 'acknowledgment_reference'])]
class OutgoingDelivery extends Model
{
    protected $primaryKey = 'recipient_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['delivery_date' => 'date', 'acknowledgment_date' => 'date'];
    }
}
