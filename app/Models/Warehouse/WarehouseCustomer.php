<?php
namespace App\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
class WarehouseCustomer extends Model {
 use HasUlids, SoftDeletes; protected $table='wh_customers';
 protected $fillable=['code','name','customer_type','outlet_id','customer_group_id','price_tier_id','contact_name','phone','email','tax_number','tax_name','tax_address','credit_term_days','credit_limit','currency_code','notes','is_active','created_by_user_id','updated_by_user_id'];
 protected function casts(): array { return ['credit_term_days'=>'integer','credit_limit'=>'decimal:4','is_active'=>'boolean']; }
 public function group(): BelongsTo { return $this->belongsTo(WarehouseCustomerGroup::class,'customer_group_id'); }
 public function priceTier(): BelongsTo { return $this->belongsTo(WarehouseCustomerPriceTier::class,'price_tier_id'); }
 public function addresses(): HasMany { return $this->hasMany(WarehouseCustomerAddress::class,'customer_id'); }
}
