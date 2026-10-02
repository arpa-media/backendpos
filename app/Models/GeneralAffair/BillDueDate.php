<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillDueDate extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'ga_bill_due_dates';
    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'nominal' => 'decimal:2',
    ];
}
