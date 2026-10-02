<?php

namespace App\Models\GeneralAffair;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CctvRequest extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'ga_cctv_requests';

    protected $fillable = [
        'request_no', 'requester_user_id', 'officer_name_snapshot', 'officer_nisj_snapshot',
        'cctv_category_id', 'category_code_snapshot', 'category_name_snapshot', 'category_custom_value',
        'outlet_id', 'outlet_code_snapshot', 'outlet_name_snapshot', 'description', 'status', 'manager_note',
        'submitted_at', 'completed_at', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester() { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function category() { return $this->belongsTo(CctvCategory::class, 'cctv_category_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function attachments() { return $this->hasMany(CctvRequestAttachment::class, 'cctv_request_id')->orderBy('created_at'); }
    public function events() { return $this->hasMany(CctvRequestEvent::class, 'cctv_request_id')->orderByDesc('event_at')->orderByDesc('id'); }
}
