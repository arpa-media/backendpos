<?php
namespace App\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class WarehouseCustomerAddress extends Model { use HasUlids, SoftDeletes; protected $table='wh_customer_addresses'; protected $fillable=['customer_id','label','recipient_name','phone','address','city','province','postal_code','latitude','longitude','is_default','is_active']; protected function casts(): array { return ['latitude'=>'decimal:8','longitude'=>'decimal:8','is_default'=>'boolean','is_active'=>'boolean']; } }
