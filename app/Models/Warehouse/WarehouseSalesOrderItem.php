<?php
namespace App\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUlids; use Illuminate\Database\Eloquent\Model;
class WarehouseSalesOrderItem extends Model { use HasUlids; protected $table='wh_sales_order_items'; protected $guarded=[]; protected function casts():array{return ['price_snapshot'=>'array','conversion_factor'=>'decimal:8','ordered_qty'=>'decimal:4','ordered_qty_base'=>'decimal:4','reserved_qty_base'=>'decimal:4','fulfilled_qty_base'=>'decimal:4','unit_price'=>'decimal:4','line_total'=>'decimal:4'];} public function sku(){return $this->belongsTo(WarehouseSku::class,'sku_id');} }
