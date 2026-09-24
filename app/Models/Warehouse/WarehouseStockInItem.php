<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockInItem extends Model
{
    use HasUlids;
    protected $table = 'wh_stock_in_items';
    protected $fillable = ['stock_in_id','purchase_order_item_id','sku_id','storage_id','batch_id','expected_qty_base','accepted_qty_base','unit_cost','package_qty_base','label_count','stored_label_count','batch_code','production_date','expiry_date','status','notes'];
    protected function casts(): array { return ['expected_qty_base'=>'decimal:4','accepted_qty_base'=>'decimal:4','unit_cost'=>'decimal:6','package_qty_base'=>'decimal:4','label_count'=>'integer','stored_label_count'=>'integer','production_date'=>'date:Y-m-d','expiry_date'=>'date:Y-m-d']; }
    public function stockIn(){ return $this->belongsTo(WarehouseStockIn::class,'stock_in_id'); }
    public function orderItem(){ return $this->belongsTo(WarehouseSupplierPurchaseOrderItem::class,'purchase_order_item_id'); }
    public function sku(){ return $this->belongsTo(WarehouseSku::class,'sku_id')->withTrashed(); }
    public function storage(){ return $this->belongsTo(WarehouseStorage::class,'storage_id')->withTrashed(); }
    public function batch(){ return $this->belongsTo(WarehouseBatch::class,'batch_id')->withTrashed(); }
    public function task(){ return $this->hasOne(WarehouseKeeperTask::class,'stock_in_item_id'); }
    public function units(){ return $this->hasMany(WarehouseStockInUnit::class,'stock_in_item_id'); }
}
