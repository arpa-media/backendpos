<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosProvisionControl extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'allow_provision',
        'notes',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'allow_provision' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
