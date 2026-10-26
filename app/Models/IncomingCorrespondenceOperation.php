<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['correspondence_id', 'status', 'responsible_user_id', 'responsible_department_id', 'due_date', 'response_required', 'response_completed_at', 'response_completed_by', 'closed_at', 'closed_by', 'closure_remarks', 'revision'])]
class IncomingCorrespondenceOperation extends Model
{
    protected $primaryKey = 'correspondence_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['due_date' => 'date', 'response_required' => 'boolean', 'response_completed_at' => 'datetime', 'closed_at' => 'datetime', 'revision' => 'integer'];
    }
}
