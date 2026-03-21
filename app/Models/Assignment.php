<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'employee_id',
        'outlet_id',
        'hr_assignment_id',
        'role_title',
        'start_date',
        'end_date',
        'is_primary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
            'imported_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
