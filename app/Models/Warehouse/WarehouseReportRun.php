<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseReportRun extends Model
{
    use HasUlids;

    protected $table = 'wh_report_runs';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'metadata' => 'array',
            'row_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by_user_id'); }
}
