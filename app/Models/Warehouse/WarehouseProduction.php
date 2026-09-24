<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProduction extends Model
{
    use HasUlids;

    protected $table = 'wh_productions';

    protected $fillable = [
        'production_number','warehouse_id','production_date','status','lock_version',
        'planned_input_value','actual_input_value','actual_output_value','selected_output_value','yield_variance_value',
        'input_ledger_posting_id','output_ledger_posting_id','input_idempotency_key',
        'input_payload_fingerprint','done_idempotency_key','done_payload_fingerprint','output_idempotency_key','output_payload_fingerprint',
        'notes','metadata','created_by_user_id','updated_by_user_id','submitted_by_user_id',
        'submitted_at','materials_released_by_user_id','materials_released_at',
        'production_done_by_user_id','production_done_at','approved_by_user_id','approved_at',
        'print_count','last_printed_at','last_printed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'production_date'=>'date:Y-m-d','planned_input_value'=>'decimal:2','actual_input_value'=>'decimal:2',
            'actual_output_value'=>'decimal:2','selected_output_value'=>'decimal:2','yield_variance_value'=>'decimal:2','lock_version'=>'integer',
            'metadata'=>'array','submitted_at'=>'datetime','materials_released_at'=>'datetime',
            'production_done_at'=>'datetime','approved_at'=>'datetime','last_printed_at'=>'datetime',
            'print_count'=>'integer',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function inputs() { return $this->hasMany(WarehouseProductionInput::class, 'production_id'); }
    public function outputs() { return $this->hasMany(WarehouseProductionOutput::class, 'production_id'); }
    public function tasks() { return $this->hasMany(WarehouseProductionTask::class, 'production_id'); }
    public function inputLedgerPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'input_ledger_posting_id'); }
    public function outputLedgerPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'output_ledger_posting_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function materialsReleasedBy() { return $this->belongsTo(User::class, 'materials_released_by_user_id'); }
    public function productionDoneBy() { return $this->belongsTo(User::class, 'production_done_by_user_id'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by_user_id'); }
}
