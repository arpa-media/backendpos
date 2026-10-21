<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrV13I04OutletCompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Opt-in mapping import from approved V13 roadmap. Defaults to preview only. */
final class HrV13I04MapOutletsCommand extends Command
{
    protected $signature = 'hr:v13-i04:map-outlets
        {--apply : Persist nonconflicting updates; omitted means dry-run}
        {--confirm= : Must equal APPLY HR V13 I04 MAPPING when --apply is used}
        {--allow-reassign : Explicitly accept overwriting existing Finance PT mapping}
        {--region-only : Update wilayah without changing Finance PT}';

    protected $description = 'Preview/apply approved region and company code mapping; no outlet inserts, never wipes unknown mappings.';

    private const REGIONS = [
        'Malang' => ['CFT','KJN','SWJ','MDC','BGN','FEB','JFL','FIA','IJN','KPD','MCP','MOG','SMR','SHT','SKN','TNS','BGE','MTS'],
        'Bali' => ['DPN','KTA'],
        'Bandung' => ['BD'],
        'Borneo' => ['BJ','BJB'],
    ];
    private const COMPANIES = [
        'BKJB' => ['CFT','KJN','SWJ','MDC','BGN','FEB','DPN','IJN','KPD','MOG','SMR','SHT','SKN'],
        'MDMF' => ['BD','BJ','KTA','FIA','TNS','MCP','BGE','MTS','BJB'],
    ];

    public function handle(HrV13I04OutletCompanyService $service): int
    {
        $service->assertReady();
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'APPLY HR V13 I04 MAPPING') {
            $this->error('BLOCKED: gunakan --confirm="APPLY HR V13 I04 MAPPING" untuk --apply.');
            return self::FAILURE;
        }
        $regionOnly = (bool) $this->option('region-only');
        $force = (bool) $this->option('allow-reassign');
        $regions = []; $companies = [];
        foreach (self::REGIONS as $name => $codes) foreach ($codes as $code) {
            if (isset($regions[$code])) { $this->error("Duplicate code {$code}"); return self::FAILURE; }
            $regions[$code] = $name;
        }
        foreach (self::COMPANIES as $name => $codes) foreach ($codes as $code) {
            if (isset($companies[$code])) { $this->error("Duplicate company for {$code}"); return self::FAILURE; }
            $companies[$code] = $name;
        }
        $allCodes = array_unique([...array_keys($regions), ...array_keys($companies)]);
        $rows = DB::table('outlets')->get();
        $byCode = $rows->groupBy(fn ($row) => strtoupper(trim((string) $row->code)));
        $stats = ['region_update' => 0, 'pt_update' => 0, 'no_change' => 0,
            'missing' => [], 'conflicts' => [], 'errors' => []];
        foreach ($allCodes as $code) {
            $found = $byCode->get($code);
            if (! $found || $found->isEmpty()) { $stats['missing'][] = $code; continue; }
            if ($found->count() !== 1) { $stats['errors'][] = "Duplikat kode outlet {$code}"; continue; }
            $outlet = $found->first();
            $oldRegion = $outlet->hr_region ?? null;
            $oldCompany = $service->companyCode((string) $outlet->id);
            $newRegion = $regions[$code] ?? $oldRegion;
            $newCompany = $companies[$code] ?? $oldCompany;
            $ptChange = ! $regionOnly && $newCompany !== $oldCompany;
            $regionChange = $newRegion !== $oldRegion;
            if ($regionChange) $stats['region_update']++;
            if ($ptChange) $stats['pt_update']++;
            if (! $regionChange && ! $ptChange) $stats['no_change']++;
            $conflict = $ptChange && $oldCompany !== null && ! $force;
            $label = sprintf('%-4s %-9s → %-9s %-5s → %-5s', $code, $oldRegion ?: '-', $newRegion ?: '-',
                $oldCompany ?: '-', $newCompany ?: '-');
            $this->line(($conflict ? '[CONFLICT] ' : ($regionChange || $ptChange ? '[CHANGE] ' : '[SAME] ')).$label);
            if ($conflict) $stats['conflicts'][] = "{$code}: {$oldCompany} → {$newCompany}";
            if (! $apply || (! $regionChange && ! $ptChange)) continue;
            try {
                // Without force, region can still be updated, existing PT mapping left untouched.
                $service->updateOutlet((string) $outlet->id, $regionChange, $newRegion,
                    $ptChange && ! $conflict, $newCompany, $force, $oldCompany, null,
                    'hr-v13-i04-cli');
            } catch (\Throwable $e) {
                $stats['errors'][] = "{$code}: {$e->getMessage()}";
            }
        }
        $this->newLine();
        $this->table(['Metric', 'Result'], [
            ['Mode', $apply ? 'APPLY' : 'DRY RUN'],
            ['Wilayah change', $stats['region_update']],
            ['PT change', $stats['pt_update']],
            ['Already equal', $stats['no_change']],
            ['Missing code', implode(', ', $stats['missing']) ?: '-'],
            ['Skipped PT reassignment (requires --allow-reassign)', implode(', ', $stats['conflicts']) ?: '-'],
            ['Errors', implode(' | ', $stats['errors']) ?: '-'],
        ]);
        $this->line('JFL: wilayah Malang; PT tidak ditetapkan otomatis (mapping existing tidak dihapus).');
        if (! $apply) $this->comment('DRY RUN only. Untuk apply perlu backup DB dan --confirm=...');
        return $stats['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
