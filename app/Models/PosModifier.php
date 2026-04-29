<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosModifier extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'pos_modifiers';

    protected $fillable = [
        'outlet_id',
        'scope_group_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function notes()
    {
        return $this->hasMany(PosModifierNote::class, 'modifier_id')->orderBy('sort_order')->orderBy('id');
    }

    public function assignments()
    {
        return $this->hasMany(PosModifierAssignment::class, 'modifier_id');
    }
}
