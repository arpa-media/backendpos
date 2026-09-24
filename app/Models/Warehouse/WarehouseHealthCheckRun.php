<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseHealthCheckRun extends Model
{
    use HasUlids;
    protected $table = 'wh_health_check_runs';
    protected $guarded = [];
    protected function casts(): array { return ['checks'=>'array','started_at'=>'datetime','completed_at'=>'datetime']; }
    public function warehouse(){ return $this->belongsTo(Outlet::class,'warehouse_id'); }
    public function executedBy(){ return $this->belongsTo(User::class,'executed_by_user_id'); }
}
