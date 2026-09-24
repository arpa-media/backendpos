<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockRequestHandoff extends Model
{
    use HasUlids;

    public const MODE_DIRECT_INTERNAL_PO = 'direct_internal_po';
    public const MODE_MANUAL_PURCHASING_REVIEW = 'manual_purchasing_review';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';

    protected $table = 'wh_stock_request_handoffs';

    protected $fillable = [
        'stock_request_id', 'warehouse_id', 'chain_supply_id', 'handoff_mode',
        'status', 'purchase_order_id', 'idempotency_key', 'payload_fingerprint',
        'error_message', 'metadata', 'generated_by_user_id', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function request() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function chainSupply() { return $this->belongsTo(WarehouseChainSupply::class, 'chain_supply_id'); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by_user_id'); }
}
