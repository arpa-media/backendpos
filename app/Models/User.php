<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;
    use HasUlids;

    protected $fillable = [
        'name',
        'nisj',
        'username',
        'email',
        'password',
        'outlet_id',
        'hr_user_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'imported_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function accessAssignment()
    {
        return $this->hasOne(UserAccessAssignment::class);
    }

    public function reportOutletAssignments()
    {
        return $this->hasMany(UserReportOutletAssignment::class);
    }
}

