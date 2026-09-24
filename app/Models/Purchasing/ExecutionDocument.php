<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class ExecutionDocument extends Model {
 use HasUlids, SoftDeletes;
 protected $guarded=[];
 protected function casts(): array { return ['document_date'=>'date:Y-m-d','subtotal'=>'decimal:2','tax_amount'=>'decimal:2','total_amount'=>'decimal:2','lock_version'=>'integer','posted_at'=>'datetime']; }
 public function items(){ return $this->hasMany(ExecutionDocumentItem::class,'document_id')->orderBy('line_no'); }
}
