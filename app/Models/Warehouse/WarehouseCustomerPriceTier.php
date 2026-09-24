<?php
namespace App\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class WarehouseCustomerPriceTier extends Model { use HasUlids, SoftDeletes; protected $table='wh_customer_price_tiers'; protected $fillable=['code','name','discount_percent','is_active','created_by_user_id','updated_by_user_id']; protected function casts(): array { return ['discount_percent'=>'decimal:4','is_active'=>'boolean']; } }
