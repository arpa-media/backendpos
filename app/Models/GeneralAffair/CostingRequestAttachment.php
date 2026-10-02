<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CostingRequestAttachment extends Model
{
    use HasUlids;
    protected $table = 'ga_costing_attachments';
    protected $guarded = [];
    protected function casts(): array { return ['is_current' => 'boolean', 'size_bytes' => 'integer']; }
    public function request() { return $this->belongsTo(CostingRequest::class, 'costing_request_id'); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
}
