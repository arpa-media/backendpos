<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehousePurchaseRequestItem extends Model
{
    use HasUlids;
    protected $table = 'wh_purchase_request_items';
    protected $fillable = ['purchase_request_id','sku_id','brand_id','supplier_source_id','request_uom_id','base_uom_id','requested_qty_uom','conversion_factor_snapshot','requested_qty_base','estimated_unit_price','estimated_line_total','approved_qty_base','approval_status','notes','approval_notes','approved_by_user_id','approved_at'];
    protected function casts(): array { return ['requested_qty_uom'=>'decimal:4','conversion_factor_snapshot'=>'decimal:8','requested_qty_base'=>'decimal:4','estimated_unit_price'=>'decimal:6','estimated_line_total'=>'decimal:2','approved_qty_base'=>'decimal:4','approved_at'=>'datetime']; }
    public function request(){ return $this->belongsTo(WarehousePurchaseRequest::class,'purchase_request_id'); }
    public function sku(){ return $this->belongsTo(WarehouseSku::class,'sku_id')->withTrashed(); }
    public function brand(){ return $this->belongsTo(WarehouseBrand::class,'brand_id')->withTrashed(); }
    public function supplier(){ return $this->belongsTo(WarehouseSupplier::class,'supplier_source_id')->withTrashed(); }
    public function requestUom(){ return $this->belongsTo(StockUom::class,'request_uom_id'); }
    public function baseUom(){ return $this->belongsTo(StockUom::class,'base_uom_id'); }
    public function approvedBy(){ return $this->belongsTo(User::class,'approved_by_user_id'); }
}
