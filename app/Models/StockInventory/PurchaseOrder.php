<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_AWAITING_FINANCE_1 = 'AWAITING_FINANCE_1';
    public const STATUS_AWAITING_FINANCE_2 = 'AWAITING_FINANCE_2';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'pur_purchase_orders';

    protected $fillable = [
        'po_number',
        'stock_request_id',
        'fund_request_id',
        'supplier_source_id',
        'outlet_id',
        'source_type',
        'order_type',
        'order_date',
        'needed_date',
        'status',
        'total_amount',
        'currency',
        'submitted_by_user_id',
        'submitted_at',
        'finance_approved_1_by_user_id',
        'finance_approved_1_at',
        'finance_approved_2_by_user_id',
        'finance_approved_2_at',
        'approved_by_user_id',
        'approved_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'order_date' => 'date:Y-m-d',
            'needed_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'finance_approved_1_at' => 'datetime',
            'finance_approved_2_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function request()
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function supplierSource()
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
