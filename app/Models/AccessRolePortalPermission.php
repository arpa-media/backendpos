<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRolePortalPermission extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'access_role_id',
        'access_level_id',
        'portal_id',
        'can_view',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
        ];
    }
}
