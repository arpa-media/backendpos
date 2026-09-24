<?php

namespace App\Models\HumanResource;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrShift extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'HR_shifts';

    protected $fillable = [
        'outlet_id',
        'name',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
