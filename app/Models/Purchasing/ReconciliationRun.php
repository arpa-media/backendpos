<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class ReconciliationRun extends Model
{
    use HasUlids;
    protected $table = 'pur_reconciliation_runs';
    protected $guarded = [];
    protected $casts = ['options' => 'array', 'totals' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
