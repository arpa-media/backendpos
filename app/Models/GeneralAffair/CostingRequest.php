<?php

namespace App\Models\GeneralAffair;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostingRequest extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $table = 'ga_costing_requests';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'purchasing_handoff_at' => 'datetime',
        ];
    }

    public function category() { return $this->belongsTo(CostingCategory::class, 'costing_category_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class); }
    public function requester() { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function rejectedBy() { return $this->belongsTo(User::class, 'rejected_by_user_id'); }
    public function generatedOrder() { return $this->hasOne(CostingGeneratedOrder::class, 'costing_request_id'); }
    public function attachments() { return $this->hasMany(CostingRequestAttachment::class, 'costing_request_id')->orderBy('created_at'); }
    public function events() { return $this->hasMany(CostingRequestEvent::class, 'costing_request_id')->orderBy('event_at'); }
}
