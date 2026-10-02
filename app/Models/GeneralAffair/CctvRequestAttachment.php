<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CctvRequestAttachment extends Model
{
    use HasUlids;

    protected $table = 'ga_cctv_request_attachments';

    protected $fillable = [
        'cctv_request_id', 'kind', 'is_current', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function request() { return $this->belongsTo(CctvRequest::class, 'cctv_request_id'); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
}
