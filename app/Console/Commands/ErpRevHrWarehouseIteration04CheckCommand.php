<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class ErpRevHrWarehouseIteration04CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-04-check';
    protected $description = 'Verify ERP REV HR/Warehouse Iteration 04 Development badge and e-certificate patch';

    public function handle(): int
    {
        $checks = [
            'Development Access Matrix remains canonical' => fn () => $this->accessMatrixIsCanonical(),
            'Badge manage permission exists' => fn () => Permission::query()->where('name', 'hr.development.badge.manage')->exists(),
            'Additive badge upload route exists' => fn () => Route::has('hr.development.badge-logo-file.store') && Route::has('hr.development.badge-logo-file.delete'),
            'Additive self certificate download route exists' => fn () => Route::has('hr.development-self.certificate.download-file'),
            'Completed participant auto issues outputs' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrDevelopmentService.php'), 'ensureCompletedOutputs($developmentId,$participantId'),
            'Program output enablement backfills completed participants' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrDevelopmentService.php'), 'syncCompletedOutputsForDevelopment($devId'),
            'Achievement code is capped to schema length' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrDevelopmentService.php'), "substr((string)(\$code?:\$generated),0,100)"),
            'Self payload exposes badge/certificate completion state' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrDevelopmentService.php'), "'has_badge'")
                && $this->sourceContains(app_path('Services/HumanResource/HrDevelopmentService.php'), "'has_certificate'"),
            'Frontend uses Iteration 04 badge upload endpoint' => fn () => $this->frontendContains('lib/humanResourceDevelopmentApi.js', '/badge-logo-file'),
            'Frontend uses Iteration 04 certificate download endpoint' => fn () => $this->frontendContains('lib/humanResourceDevelopmentApi.js', '/download-file'),
            'Admin Development provides image preview upload' => fn () => $this->frontendContains('pages/human-resource/HumanResourceDevelopmentPage.vue', 'badgeLogoPreview')
                && $this->frontendContains('pages/human-resource/HumanResourceDevelopmentPage.vue', 'Pilih Foto / Logo'),
            'Dashboard Development explains completed badge lifecycle' => fn () => $this->frontendContains('pages/human-resource/components/DevelopmentSelfServicePanel.vue', 'Badge otomatis tersedia ketika Development sudah berstatus completed')
                && $this->frontendContains('pages/human-resource/components/DevelopmentSelfServicePanel.vue', 'Unduh e-Certificate'),
            'No completed badge output is missing after migration' => fn () => $this->missingOutputCount('badge') === 0,
            'No completed certificate output is missing after migration' => fn () => $this->missingOutputCount('certificate') === 0,
        ];

        $failed = 0;
        foreach ($checks as $label => $callback) {
            try {
                $ok = (bool) $callback();
            } catch (\Throwable $e) {
                $ok = false;
                $this->line(sprintf('<error>[FAIL]</error> %s — %s', $label, $e->getMessage()));
                $failed++;
                continue;
            }
            $this->line(sprintf('%s %s', $ok ? '<info>[OK]</info>' : '<error>[FAIL]</error>', $label));
            if (! $ok) $failed++;
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("Iteration 04 check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP REV HR/Warehouse Iteration 04 check passed.');
        return self::SUCCESS;
    }

    private function accessMatrixIsCanonical(): bool
    {
        if (! Schema::hasTable('access_menus')) return false;
        $row = DB::table('access_menus')->where(function ($query): void {
            $query->where('code', 'hr-development')->orWhere('path', '/human-resource/development');
        })->first();
        return $row
            && (string) ($row->permission_view ?? '') === 'hr.development.view'
            && (string) ($row->permission_create ?? '') === 'hr.development.create'
            && (string) ($row->permission_update ?? '') === 'hr.development.update'
            && (string) ($row->permission_delete ?? '') === 'hr.development.delete';
    }

    private function missingOutputCount(string $type): int
    {
        if (! Schema::hasTable('HR_development_participants') || ! Schema::hasTable('HR_developments') || ! Schema::hasTable('HR_development_achievements')) return 0;
        $column = $type === 'badge' ? 'output_badge' : 'output_certificate';
        return DB::table('HR_development_participants as p')
            ->join('HR_developments as d', 'd.id', '=', 'p.development_id')
            ->where('p.status', 'completed')
            ->whereNotNull('p.completed_at')
            ->where("d.{$column}", true)
            ->whereNull('d.deleted_at')
            ->whereNotExists(function ($query) use ($type): void {
                $query->selectRaw('1')
                    ->from('HR_development_achievements as a')
                    ->whereColumn('a.participant_id', 'p.id')
                    ->where('a.type', $type);
            })
            ->count();
    }

    private function sourceContains(string $path, string $needle): bool
    {
        return is_file($path) && str_contains((string) file_get_contents($path), $needle);
    }

    private function frontendContains(string $relative, string $needle): bool
    {
        return $this->sourceContains(base_path('../frontend - Backoffice/src/'.$relative), $needle);
    }
}
