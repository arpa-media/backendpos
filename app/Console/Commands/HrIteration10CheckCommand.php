<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration10CheckCommand extends Command
{
    protected $signature = 'hr:iteration-10-check';
    protected $description = 'Runtime contract check HR Iteration 10 Announcement.';

    public function handle(): int
    {
        $tables = [
            'HR_announcements',
            'HR_announcement_targets',
            'HR_announcement_attachments',
            'HR_announcement_polls',
            'HR_announcement_poll_options',
            'HR_announcement_poll_votes',
        ];
        $checks = [];
        foreach ($tables as $table) $checks["Table {$table}"] = Schema::hasTable($table);

        $checks['Access menu hr-announcement'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'hr-announcement')->where('path', '/human-resource/announcement')->where('is_active', true)->exists();
        foreach (['hr.announcement.view', 'hr.announcement.create', 'hr.announcement.update', 'hr.announcement.delete', 'hr.announcement.publish', 'hr.announcement.results', 'hr.announcement.export'] as $permission) {
            $checks["Permission {$permission}"] = Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
        }
        $checks['Zlib compression'] = function_exists('gzencode') && function_exists('gzdecode');

        $failed = false;
        foreach ($checks as $name => $ok) {
            $ok ? $this->components->info("PASS · {$name}") : $this->components->error("FAIL · {$name}");
            $failed = $failed || ! $ok;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
