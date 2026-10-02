<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CctvCategory extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'ga_cctv_categories';

    protected $fillable = [
        'code', 'name', 'description', 'sort_order', 'is_active', 'allow_custom_value',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'allow_custom_value' => 'boolean',
        ];
    }

    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by_user_id'); }
}
