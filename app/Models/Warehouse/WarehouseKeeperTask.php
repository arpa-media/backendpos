<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseKeeperTask extends Model
{
    use HasUlids;
    protected $table = 'wh_keeper_tasks';
    protected $fillable = ['warehouse_id','stock_in_id','stock_in_item_id','assigned_to_user_id','assigned_by_user_id','status','assigned_at','started_at','completed_at','metadata'];
    protected function casts(): array { return ['assigned_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','metadata'=>'array']; }
    public function warehouse(){ return $this->belongsTo(Outlet::class,'warehouse_id'); }
    public function stockIn(){ return $this->belongsTo(WarehouseStockIn::class,'stock_in_id'); }
    public function item(){ return $this->belongsTo(WarehouseStockInItem::class,'stock_in_item_id'); }
    public function assignedTo(){ return $this->belongsTo(User::class,'assigned_to_user_id'); }
}
