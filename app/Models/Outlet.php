<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'hr_outlet_id',
        'code',
        'name',
        'type',
        'address',
        'phone',
        'timezone',
        'latitude',
        'longitude',
        'radius_m',
        'is_hr_source',
        'is_compatibility_stub',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
            'is_hr_source' => 'boolean',
            'is_compatibility_stub' => 'boolean',
            'is_active' => 'boolean',
            'imported_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }
}
