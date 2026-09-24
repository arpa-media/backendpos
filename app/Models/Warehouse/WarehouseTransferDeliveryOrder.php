<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseTransferDeliveryOrder extends Model
{
    use HasUlids;

    protected $table = 'wh_transfer_delivery_orders';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimated_delivery_date' => 'date:Y-m-d', 'generated_at' => 'datetime', 'dispatched_at' => 'datetime',
            'document_snapshot' => 'array', 'print_count' => 'integer', 'last_printed_at' => 'datetime',
        ];
    }

    public function transfer() { return $this->belongsTo(WarehouseStockTransfer::class, 'transfer_id'); }
    public function originWarehouse() { return $this->belongsTo(Outlet::class, 'origin_warehouse_id'); }
    public function destinationWarehouse() { return $this->belongsTo(Outlet::class, 'destination_warehouse_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_user_id'); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function dispatchedBy() { return $this->belongsTo(User::class, 'dispatched_by_user_id'); }
    public function dispatchLedgerPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'dispatch_ledger_posting_id'); }
    public function lastPrintedBy() { return $this->belongsTo(User::class, 'last_printed_by_user_id'); }
}
