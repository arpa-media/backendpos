<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosModifierNote extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'pos_modifier_notes';

    protected $fillable = [
        'modifier_id',
        'note',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function modifier()
    {
        return $this->belongsTo(PosModifier::class, 'modifier_id');
    }
}
