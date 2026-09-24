<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAnnouncementAttachment extends Model
{
    use HasUlids, SoftDeletes;
    protected $table = 'HR_announcement_attachments';
    protected $guarded = [];
    protected function casts(): array { return ['purged_at' => 'datetime']; }
    public function announcement() { return $this->belongsTo(HrAnnouncement::class, 'announcement_id'); }
}
