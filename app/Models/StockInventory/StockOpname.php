<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    use HasUlids;

    protected $table = 'stk_stock_opnames';

    protected $fillable = [
        'outlet_id',
        'opname_date',
        'status',
        'notes',
        'submitted_by_user_id',
        'submitted_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class, 'stock_opname_id');
    }


    public function cancellationRequests()
    {
        return $this->hasMany(StockCancellationRequest::class, 'document_id')
            ->where('document_type', StockCancellationRequest::TYPE_STOCK_OPNAME);
    }

    public function latestCancellation()
    {
        return $this->hasOne(StockCancellationRequest::class, 'document_id')
            ->where('document_type', StockCancellationRequest::TYPE_STOCK_OPNAME)
            ->latestOfMany('requested_at');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
