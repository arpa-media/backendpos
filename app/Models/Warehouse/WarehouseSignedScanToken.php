<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseSignedScanToken extends Model
{
    use HasUlids;

    protected $table = 'wh_signed_scan_tokens';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array', 'issued_at' => 'datetime', 'expires_at' => 'datetime',
            'processing_at' => 'datetime', 'consumed_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function user() { return $this->belongsTo(User::class, 'user_id'); }
}
