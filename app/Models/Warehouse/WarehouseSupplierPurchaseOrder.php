<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseSupplierPurchaseOrder extends Model
{
    use HasUlids;
    protected $table = 'wh_supplier_purchase_orders';
    protected $fillable = ['po_number','purchase_request_id','warehouse_id','supplier_source_id','buyer_user_id','status','currency','estimated_total','actual_total','notes','lock_version','assigned_by_user_id','assigned_at','purchased_by_user_id','purchased_at','purchase_approved_by_user_id','purchase_approved_at','created_by_user_id','updated_by_user_id'];
    protected function casts(): array { return ['estimated_total'=>'decimal:2','actual_total'=>'decimal:2','lock_version'=>'integer','assigned_at'=>'datetime','purchased_at'=>'datetime','purchase_approved_at'=>'datetime']; }
    public function request(){ return $this->belongsTo(WarehousePurchaseRequest::class,'purchase_request_id'); }
    public function warehouse(){ return $this->belongsTo(Outlet::class,'warehouse_id'); }
    public function supplier(){ return $this->belongsTo(WarehouseSupplier::class,'supplier_source_id')->withTrashed(); }
    public function buyer(){ return $this->belongsTo(User::class,'buyer_user_id'); }
    public function items(){ return $this->hasMany(WarehouseSupplierPurchaseOrderItem::class,'purchase_order_id'); }
    public function invoices(){ return $this->hasMany(WarehousePurchaseInvoice::class,'purchase_order_id'); }
    public function stockIn(){ return $this->hasOne(WarehouseStockIn::class,'purchase_order_id'); }
}
