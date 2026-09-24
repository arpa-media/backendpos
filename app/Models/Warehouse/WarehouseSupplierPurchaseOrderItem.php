<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseSupplierPurchaseOrderItem extends Model
{
    use HasUlids;
    protected $table = 'wh_supplier_purchase_order_items';
    protected $fillable = ['purchase_order_id','purchase_request_item_id','sku_id','base_uom_id','sku_code_snapshot','item_name_snapshot','base_uom_code_snapshot','base_uom_name_snapshot','ordered_qty_base','estimated_unit_price','estimated_line_total','actual_qty_base','actual_unit_price','actual_line_total','status','notes'];
    protected function casts(): array { return ['ordered_qty_base'=>'decimal:4','estimated_unit_price'=>'decimal:6','estimated_line_total'=>'decimal:2','actual_qty_base'=>'decimal:4','actual_unit_price'=>'decimal:6','actual_line_total'=>'decimal:2']; }
    public function order(){ return $this->belongsTo(WarehouseSupplierPurchaseOrder::class,'purchase_order_id'); }
    public function requestItem(){ return $this->belongsTo(WarehousePurchaseRequestItem::class,'purchase_request_item_id'); }
    public function sku(){ return $this->belongsTo(WarehouseSku::class,'sku_id')->withTrashed(); }
    public function baseUom(){ return $this->belongsTo(StockUom::class,'base_uom_id'); }
}
