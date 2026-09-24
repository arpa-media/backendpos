<?php

namespace App\Models\Purchasing;

use App\Models\Outlet;
use App\Models\StockInventory\SupplierSource;
use App\Models\User;
use App\Models\Warehouse\WarehouseStockRequest;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderDocument extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'pur_purchase_orders';

    protected $fillable = [
        'po_number',
        'stock_request_id',
        'fund_request_id',
        'supplier_source_id',
        'counterparty_name',
        'outlet_id',
        'chamber_code',
        'scope_type',
        'company_code',
        'marking',
        'source_type',
        'order_type',
        'order_date',
        'needed_date',
        'status',
        'currency',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'lock_version',
        'submitted_by_user_id',
        'submitted_at',
        'finance_approved_1_by_user_id',
        'finance_approved_1_at',
        'finance_approved_2_by_user_id',
        'finance_approved_2_at',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_notes',
        'warehouse_handoff_key',
        'warehouse_handoff_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'needed_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'finance_approved_1_at' => 'datetime',
            'finance_approved_2_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'warehouse_handoff_at' => 'datetime',
        ];
    }

    public function fundRequest() { return $this->belongsTo(FundRequest::class, 'fund_request_id'); }
    public function stockRequest() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function supplierSource() { return $this->belongsTo(SupplierSource::class, 'supplier_source_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function items() { return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('line_no'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by_user_id'); }
    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function financeApproved1By() { return $this->belongsTo(User::class, 'finance_approved_1_by_user_id'); }
    public function financeApproved2By() { return $this->belongsTo(User::class, 'finance_approved_2_by_user_id'); }
    public function rejectedBy() { return $this->belongsTo(User::class, 'rejected_by_user_id'); }
}
