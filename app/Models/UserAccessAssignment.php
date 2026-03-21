<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAccessAssignment extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'access_role_id',
        'access_level_id',
        'assigned_by_user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(AccessRole::class, 'access_role_id');
    }

    public function level()
    {
        return $this->belongsTo(AccessLevel::class, 'access_level_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
