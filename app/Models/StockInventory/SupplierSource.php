<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierSource extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'pur_supplier_sources';

    protected $fillable = [
        'code',
        'name',
        'source_type',
        'contact_name',
        'phone',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function priceLists()
    {
        return $this->hasMany(PriceList::class, 'supplier_source_id');
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
