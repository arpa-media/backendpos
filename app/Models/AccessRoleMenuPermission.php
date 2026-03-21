<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRoleMenuPermission extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'access_role_id',
        'access_level_id',
        'menu_id',
        'can_view',
        'can_create',
        'can_edit',
        'can_delete',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    public function menu()
    {
        return $this->belongsTo(AccessMenu::class, 'menu_id');
    }
}
