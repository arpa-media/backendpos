<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehousePurchaseInvoice extends Model
{
    use HasUlids;
    protected $table = 'wh_purchase_invoices';
    protected $fillable = ['purchase_order_id','original_name','storage_disk','storage_path','mime_type','file_size','sha256','uploaded_by_user_id','uploaded_at'];
    protected function casts(): array { return ['file_size'=>'integer','uploaded_at'=>'datetime']; }
    public function order(){ return $this->belongsTo(WarehouseSupplierPurchaseOrder::class,'purchase_order_id'); }
    public function uploadedBy(){ return $this->belongsTo(User::class,'uploaded_by_user_id'); }
}
