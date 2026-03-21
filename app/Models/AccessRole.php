<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRole extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_type_id',
        'code',
        'name',
        'description',
        'spatie_role_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function userType()
    {
        return $this->belongsTo(AccessUserType::class, 'user_type_id');
    }
}
