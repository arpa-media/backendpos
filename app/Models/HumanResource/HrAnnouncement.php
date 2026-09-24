<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAnnouncement extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'HR_announcements';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function targets() { return $this->hasMany(HrAnnouncementTarget::class, 'announcement_id'); }
    public function attachments() { return $this->hasMany(HrAnnouncementAttachment::class, 'announcement_id'); }
    public function poll() { return $this->hasOne(HrAnnouncementPoll::class, 'announcement_id'); }
}
