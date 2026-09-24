<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ParStock extends Model
{
    use HasUlids;

    protected $table = 'stk_par_stocks';

    protected $fillable = [
        'outlet_id',
        'sku_id',
        'par_qty',
        'minimum_qty',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'par_qty' => 'decimal:4',
            'minimum_qty' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
