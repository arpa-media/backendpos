<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseLedgerPosting extends Model
{
    use HasUlids;

    protected $table = 'wh_ledger_postings';

    protected $fillable = [
        'warehouse_id', 'idempotency_key', 'movement_type', 'reference_type', 'reference_id',
        'business_date', 'status', 'reason', 'metadata', 'reversal_of_id', 'posted_by_user_id',
        'posted_at', 'reversed_by_user_id', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function entries() { return $this->hasMany(WarehouseLedgerEntry::class, 'posting_id')->orderBy('entry_order'); }
    public function reversalOf() { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function reversal() { return $this->hasOne(self::class, 'reversal_of_id'); }
    public function postedBy() { return $this->belongsTo(User::class, 'posted_by_user_id'); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by_user_id'); }
}
