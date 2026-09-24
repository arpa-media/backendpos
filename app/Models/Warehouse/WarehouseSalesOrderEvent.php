<?php
namespace App\Models\Warehouse; use Illuminate\Database\Eloquent\Concerns\HasUlids; use Illuminate\Database\Eloquent\Model;
class WarehouseSalesOrderEvent extends Model { use HasUlids; protected $table='wh_sales_order_events'; protected $guarded=[]; protected function casts():array{return ['payload'=>'array'];} }
