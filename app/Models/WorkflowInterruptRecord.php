<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowInterruptRecord extends Model
{
    protected $table = 'workflow_interrupts';

    protected $primaryKey = 'workflow_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'workflow_id',
        'interrupt',
    ];
}
