<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrAnnouncementAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrAnnouncementExpirySweepCommand extends Command
{
    protected $signature = 'hr:announcement-expiry-sweep {--limit=200 : Maksimal announcement per eksekusi}';
    protected $description = 'Expire HR announcements yang periodenya selesai dan purge attachment private storage.';

    public function handle(HrAnnouncementAttachmentService $attachments): int
    {
        if (! Schema::hasTable('HR_announcements') || ! Schema::hasTable('HR_announcement_attachments')) {
            $this->components->info('Announcement Iteration 10 belum dimigrasikan.');
            return self::SUCCESS;
        }

        $limit = max(1, min((int) $this->option('limit'), 1000));
        $rows = DB::table('HR_announcements as a')
            ->whereNull('a.deleted_at')
            ->whereNotNull('a.ends_at')
            ->where('a.ends_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->where('a.status', 'published')
                    ->orWhere(function (Builder $expired): void {
                        $expired->where('a.status', 'expired')
                            ->whereExists(function ($attachments): void {
                                $attachments->selectRaw('1')
                                    ->from('HR_announcement_attachments as aa')
                                    ->whereColumn('aa.announcement_id', 'a.id')
                                    ->whereNull('aa.deleted_at')
                                    ->whereNull('aa.purged_at');
                            });
                    });
            })
            ->orderBy('a.ends_at')
            ->limit($limit)
            ->get(['a.id', 'a.status']);

        $expiredCount = 0;
        $purgedCount = 0;
        $failedCount = 0;
        foreach ($rows as $row) {
            try {
                if ((string) $row->status !== 'expired') {
                    DB::table('HR_announcements')->where('id', $row->id)->update([
                        'status' => 'expired',
                        'expired_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $expiredCount++;
                }
                $purgedCount += $attachments->purgeAnnouncement((string) $row->id, 'announcement_expired');
            } catch (\Throwable $exception) {
                $failedCount++;
                report($exception);
                $this->components->warn('Gagal sweep announcement '.(string) $row->id.': '.$exception->getMessage());
            }
        }

        $this->components->info("Expired {$expiredCount} announcement; purged {$purgedCount} attachment; failed {$failedCount}.");
        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
