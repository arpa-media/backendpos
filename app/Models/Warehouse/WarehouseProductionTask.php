<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductionTask extends Model
{
    use HasUlids;
    protected $table = 'wh_production_tasks';
    protected $fillable = [
        'warehouse_id','production_id','production_input_id','assigned_to_user_id',
        'assigned_by_user_id','status','assigned_at','started_at','completed_at','metadata',
    ];
    protected function casts(): array
    {
        return ['assigned_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','metadata'=>'array'];
    }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function production() { return $this->belongsTo(WarehouseProduction::class, 'production_id'); }
    public function input() { return $this->belongsTo(WarehouseProductionInput::class, 'production_input_id'); }
    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function assignedBy() { return $this->belongsTo(User::class, 'assigned_by_user_id'); }
}
