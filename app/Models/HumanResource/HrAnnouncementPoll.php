<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAnnouncementPoll extends Model
{
    use HasUlids;
    protected $table = 'HR_announcement_polls';
    protected $guarded = [];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function announcement() { return $this->belongsTo(HrAnnouncement::class, 'announcement_id'); }
    public function options() { return $this->hasMany(HrAnnouncementPollOption::class, 'poll_id')->orderBy('sort_order'); }
    public function votes() { return $this->hasMany(HrAnnouncementPollVote::class, 'poll_id'); }
}
