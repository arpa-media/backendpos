<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosModifierAssignment extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'pos_modifier_assignments';

    protected $fillable = [
        'modifier_id',
        'assignable_type',
        'assignable_id',
    ];

    public function modifier()
    {
        return $this->belongsTo(PosModifier::class, 'modifier_id');
    }
}
