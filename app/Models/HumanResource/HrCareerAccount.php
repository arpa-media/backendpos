<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class HrCareerAccount extends Authenticatable
{
    use HasApiTokens, HasUlids, Notifiable;

    protected $table = 'HR_career_accounts';

    protected $fillable = [
        'registration_request_id', 'nik', 'username', 'full_name', 'phone', 'email', 'password',
        'is_active', 'must_change_password', 'profile_completed_at', 'application_blocked_at',
        'application_block_reason', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'profile_completed_at' => 'datetime',
            'application_blocked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
