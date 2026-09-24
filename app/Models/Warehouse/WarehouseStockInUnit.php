<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockInUnit extends Model
{
    use HasUlids;
    protected $table = 'wh_stock_in_units';
    protected $fillable = ['stock_in_id','stock_in_item_id','stock_unit_id','qty_base','status','stored_by_user_id','stored_at'];
    protected function casts(): array { return ['qty_base'=>'decimal:4','stored_at'=>'datetime']; }
    public function stockIn(){ return $this->belongsTo(WarehouseStockIn::class,'stock_in_id'); }
    public function item(){ return $this->belongsTo(WarehouseStockInItem::class,'stock_in_item_id'); }
    public function stockUnit(){ return $this->belongsTo(WarehouseStockUnit::class,'stock_unit_id'); }
    public function storedBy(){ return $this->belongsTo(User::class,'stored_by_user_id'); }
}
