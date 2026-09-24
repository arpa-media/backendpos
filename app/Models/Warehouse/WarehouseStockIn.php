<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockIn extends Model
{
    use HasUlids;
    protected $table = 'wh_stock_ins';
    protected $fillable = ['stock_in_number','purchase_order_id','warehouse_id','checker_user_id','status','idempotency_key','payload_fingerprint','ledger_posting_id','print_count','last_printed_at','last_printed_by_user_id','assigned_by_user_id','assigned_at','checker_completed_by_user_id','checker_completed_at','approved_by_user_id','approved_at','notes'];
    protected function casts(): array { return ['print_count'=>'integer','last_printed_at'=>'datetime','assigned_at'=>'datetime','checker_completed_at'=>'datetime','approved_at'=>'datetime']; }
    public function order(){ return $this->belongsTo(WarehouseSupplierPurchaseOrder::class,'purchase_order_id'); }
    public function warehouse(){ return $this->belongsTo(Outlet::class,'warehouse_id'); }
    public function checker(){ return $this->belongsTo(User::class,'checker_user_id'); }
    public function items(){ return $this->hasMany(WarehouseStockInItem::class,'stock_in_id'); }
    public function units(){ return $this->hasMany(WarehouseStockInUnit::class,'stock_in_id'); }
    public function ledgerPosting(){ return $this->belongsTo(WarehouseLedgerPosting::class,'ledger_posting_id'); }
}
