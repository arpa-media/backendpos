<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseDeliveryOrder extends Model
{
    use HasUlids;

    protected $table = 'wh_delivery_orders';

    protected $fillable = [
        'fulfillment_id', 'stock_request_id', 'warehouse_id', 'outlet_id',
        'delivery_number', 'sender_user_id', 'estimated_delivery_date',
        'estimated_delivery_time', 'status', 'notes', 'generated_by_user_id',
        'generated_at', 'dispatched_by_user_id', 'dispatched_at',
        'ledger_posting_id', 'idempotency_key', 'payload_fingerprint',
        'document_snapshot', 'print_count', 'last_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_delivery_date' => 'date:Y-m-d',
            'generated_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'document_snapshot' => 'array',
            'print_count' => 'integer',
            'last_printed_at' => 'datetime',
        ];
    }

    public function fulfillment() { return $this->belongsTo(WarehouseFulfillment::class, 'fulfillment_id'); }
    public function request() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_user_id'); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function dispatchedBy() { return $this->belongsTo(User::class, 'dispatched_by_user_id'); }
    public function ledgerPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'ledger_posting_id'); }
    public function items() { return $this->hasMany(WarehouseDeliveryOrderItem::class, 'delivery_order_id'); }
}
