<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration24CheckCommand extends Command
{
    protected $signature = 'hr:iteration-24-check';
    protected $description = 'Validate HR Iteration 24 announcement and Development V2.1 hardening.';

    public function handle(): int
    {
        $checks = [
            'Development badge logo path column' => Schema::hasTable('HR_developments') && Schema::hasColumn('HR_developments', 'badge_logo_path'),
            'Development badge logo metadata' => Schema::hasTable('HR_developments')
                && Schema::hasColumn('HR_developments', 'badge_logo_mime')
                && Schema::hasColumn('HR_developments', 'badge_logo_original_name')
                && Schema::hasColumn('HR_developments', 'badge_logo_size_bytes')
                && Schema::hasColumn('HR_developments', 'badge_logo_updated_at'),
            'Development lifecycle audit table' => Schema::hasTable('HR_development_program_events'),
            'Achievement type column' => Schema::hasTable('HR_development_achievements') && Schema::hasColumn('HR_development_achievements', 'type'),
            'Unpublish permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.development.unpublish')->exists(),
            'Badge manage permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.development.badge.manage')->exists(),
            'Development Access Matrix update/delete' => Schema::hasTable('access_menus') && DB::table('access_menus')
                ->where(function ($q): void { $q->where('code', 'hr-development')->orWhere('path', '/human-resource/development'); })
                ->where('permission_update', 'hr.development.update')
                ->where('permission_delete', 'hr.development.delete')
                ->exists(),
            'Announcement Access Matrix update/delete' => Schema::hasTable('access_menus') && DB::table('access_menus')
                ->where(function ($q): void { $q->where('code', 'hr-announcement')->orWhere('path', '/human-resource/announcement'); })
                ->where('permission_update', 'hr.announcement.update')
                ->where('permission_delete', 'hr.announcement.delete')
                ->exists(),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        $invalidType = Schema::hasTable('HR_development_achievements')
            ? DB::table('HR_development_achievements')->whereNull('type')->orWhereRaw("TRIM(type) = ''")->count()
            : 0;
        $this->line(($invalidType === 0 ? '[OK]' : '[FAIL]')." Achievement type populated (invalid={$invalidType})");

        $publishedWithoutCompletion = Schema::hasTable('HR_development_participants')
            ? DB::table('HR_development_participants')->whereNotNull('result_published_at')->whereNull('completed_at')->count()
            : 0;
        $this->line(($publishedWithoutCompletion === 0 ? '[OK]' : '[WARN]')." Published historical result without completed_at ({$publishedWithoutCompletion})");

        return in_array(false, $checks, true) || $invalidType > 0 ? self::FAILURE : self::SUCCESS;
    }
}
