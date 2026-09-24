<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseSupplier extends Model
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
        'email',
        'address',
        'tax_number',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
