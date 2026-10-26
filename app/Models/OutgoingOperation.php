<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['correspondence_id', 'status', 'dispatch_date', 'dispatch_channel', 'dispatch_reference', 'dispatch_remarks', 'closed_at', 'closed_by', 'closure_remarks', 'revision'])]
class OutgoingOperation extends Model
{
    protected $primaryKey = 'correspondence_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['dispatch_date' => 'date', 'closed_at' => 'datetime', 'revision' => 'integer'];
    }
}
