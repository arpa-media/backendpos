<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockTransfer extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_transfers';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date:Y-m-d', 'needed_date' => 'date:Y-m-d', 'lock_version' => 'integer',
            'requested_qty_base' => 'decimal:4', 'ready_qty_base' => 'decimal:4', 'dispatched_qty_base' => 'decimal:4',
            'received_qty_base' => 'decimal:4', 'return_qty_base' => 'decimal:4', 'not_received_qty_base' => 'decimal:4',
            'dispatched_value' => 'decimal:2', 'received_value' => 'decimal:2', 'metadata' => 'array',
            'submitted_at' => 'datetime', 'ready_at' => 'datetime', 'receiving_started_at' => 'datetime',
            'received_at' => 'datetime', 'completed_at' => 'datetime', 'receipt_print_count' => 'integer',
            'receipt_last_printed_at' => 'datetime',
        ];
    }

    public function originWarehouse() { return $this->belongsTo(Outlet::class, 'origin_warehouse_id'); }
    public function destinationWarehouse() { return $this->belongsTo(Outlet::class, 'destination_warehouse_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function receivingStartedBy() { return $this->belongsTo(User::class, 'receiving_started_by_user_id'); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function receiptLastPrintedBy() { return $this->belongsTo(User::class, 'receipt_last_printed_by_user_id'); }
    public function receiveLedgerPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'receive_ledger_posting_id'); }
    public function items() { return $this->hasMany(WarehouseStockTransferItem::class, 'transfer_id'); }
    public function tasks() { return $this->hasMany(WarehouseStockTransferTask::class, 'transfer_id'); }
    public function units() { return $this->hasMany(WarehouseStockTransferUnit::class, 'transfer_id'); }
    public function deliveryOrder() { return $this->hasOne(WarehouseTransferDeliveryOrder::class, 'transfer_id'); }
    public function lineages() { return $this->hasMany(WarehouseTransferBatchLineage::class, 'transfer_id'); }
}
