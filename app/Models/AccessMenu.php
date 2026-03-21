<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessMenu extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'portal_id',
        'code',
        'name',
        'path',
        'sort_order',
        'permission_view',
        'permission_create',
        'permission_update',
        'permission_delete',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function portal()
    {
        return $this->belongsTo(AccessPortal::class, 'portal_id');
    }
}
