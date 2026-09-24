<?php

namespace App\Models\Warehouse;

use App\Models\Assignment;
use App\Models\Outlet;
use App\Models\Purchasing\FundRequest;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockRequest extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_AWAITING_APPROVAL_PO = 'awaiting_approval_po';
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_REVIEW = 'review';
    public const STATUS_PREPARE = 'prepare';
    public const STATUS_READY = 'ready';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'stk_requests';

    protected $fillable = [
        'request_number', 'outlet_id', 'destination_warehouse_id', 'chain_supply_id',
        'origin_assignment_id', 'source_opname_id', 'request_channel', 'request_date',
        'needed_date', 'status', 'purchasing_handoff_status', 'request_approval_status',
        'request_approved_by_user_id', 'request_approved_at', 'source_key',
        'canonical_fund_request_id', 'draft_purchase_order_id', 'lock_version',
        'notes', 'submitted_by_user_id', 'submitted_at', 'warehouse_locked_at',
        'accepted_by_user_id', 'accepted_at', 'destination_snapshot',
        'request_policy_snapshot', 'decided_by_user_id', 'decided_at',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'needed_date' => 'date:Y-m-d',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'warehouse_locked_at' => 'datetime',
            'accepted_at' => 'datetime',
            'request_approved_at' => 'datetime',
            'decided_at' => 'datetime',
            'destination_snapshot' => 'array',
            'request_policy_snapshot' => 'array',
        ];
    }

    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function destinationWarehouse() { return $this->belongsTo(Outlet::class, 'destination_warehouse_id'); }
    public function chainSupply() { return $this->belongsTo(WarehouseChainSupply::class, 'chain_supply_id'); }
    public function originAssignment() { return $this->belongsTo(Assignment::class, 'origin_assignment_id'); }
    public function items() { return $this->hasMany(WarehouseStockRequestItem::class, 'stock_request_id'); }
    public function handoff() { return $this->hasOne(WarehouseStockRequestHandoff::class, 'stock_request_id'); }
    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class, 'stock_request_id'); }
    public function draftPurchaseOrder() { return $this->belongsTo(PurchaseOrder::class, 'draft_purchase_order_id'); }
    public function canonicalFundRequest() { return $this->belongsTo(FundRequest::class, 'canonical_fund_request_id'); }
    public function timelines() { return $this->hasMany(StockRequestTimeline::class, 'stock_request_id'); }
    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function acceptedBy() { return $this->belongsTo(User::class, 'accepted_by_user_id'); }
    public function requestApprovedBy() { return $this->belongsTo(User::class, 'request_approved_by_user_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
