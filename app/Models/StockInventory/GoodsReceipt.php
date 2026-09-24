<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasUlids;

    public const TYPE_WAREHOUSE = 'warehouse';
    public const TYPE_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'stk_goods_receipts';

    protected $fillable = [
        'gr_number',
        'receipt_type',
        'outlet_id',
        'purchase_order_id',
        'supplier_source_id',
        'shipment_code',
        'supplier_document_number',
        'receipt_date',
        'status',
        'currency',
        'total_amount',
        'lock_version',
        'notes',
        'received_by_user_id',
        'received_at',
        'delivery_not_before_at',
        'delivery_reference_type',
        'delivery_reference_id',
        'delivery_reference_number',
        'received_at_input_by_user_id',
        'received_at_input_at',
        'released_by_user_id',
        'released_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'lock_version' => 'integer',
            'received_at' => 'datetime',
            'delivery_not_before_at' => 'datetime',
            'received_at_input_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplierSource()
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
