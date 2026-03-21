<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'assignment_id',
        'hr_employee_id',
        'nisj',
        'full_name',
        'nickname',
        'employment_status',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }
}
