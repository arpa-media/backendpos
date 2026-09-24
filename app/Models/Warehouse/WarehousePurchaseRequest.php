<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehousePurchaseRequest extends Model
{
    use HasUlids;
    protected $table = 'wh_purchase_requests';
    protected $fillable = ['pr_number','warehouse_id','request_date','needed_date','status','notes','lock_version','submitted_by_user_id','submitted_at','decided_by_user_id','decided_at','created_by_user_id','updated_by_user_id'];
    protected function casts(): array { return ['request_date'=>'date:Y-m-d','needed_date'=>'date:Y-m-d','submitted_at'=>'datetime','decided_at'=>'datetime','lock_version'=>'integer']; }
    public function warehouse(){ return $this->belongsTo(Outlet::class,'warehouse_id'); }
    public function items(){ return $this->hasMany(WarehousePurchaseRequestItem::class,'purchase_request_id'); }
    public function orders(){ return $this->hasMany(WarehouseSupplierPurchaseOrder::class,'purchase_request_id'); }
    public function submittedBy(){ return $this->belongsTo(User::class,'submitted_by_user_id'); }
    public function decidedBy(){ return $this->belongsTo(User::class,'decided_by_user_id'); }
}
