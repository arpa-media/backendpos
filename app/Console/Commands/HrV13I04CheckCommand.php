<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrV13I04OutletCompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrV13I04CheckCommand extends Command
{
    protected $signature = 'hr:v13-i04:check';
    protected $description = 'Check HR Data PT/Wilayah schema, access matrix, and current outlet mapping without changes';

    public function handle(HrV13I04OutletCompanyService $service): int
    {
        $checks = [
            'outlets.hr_region' => Schema::hasTable('outlets') && Schema::hasColumn('outlets', 'hr_region'),
            'finance_companies' => Schema::hasTable('finance_companies'),
            'finance_outlet_company_mappings' => Schema::hasTable('finance_outlet_company_mappings'),
            'hr_v13_i04_pt_mapping_audits' => Schema::hasTable('hr_v13_i04_pt_mapping_audits'),
            'HR Data PT Access Matrix' => Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code','hr-master-company')
                    ->where('path','/human-resource/data-master/company')->exists(),
        ];
        $failed = 0;
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$name);
            if (! $ok) $failed++;
        }
        if ($failed) return self::FAILURE;
        $snapshot = $service->snapshot();
        $items = collect($snapshot['items']);
        $this->table(['Metric','Count'], [
            ['Outlets active', $items->count()],
            ['Wilayah null', $items->whereNull('hr_region')->count()],
            ['PT null', $items->whereNull('company_code')->count()],
            ['BKJB', $items->where('company_code','BKJB')->count()],
            ['MDMF', $items->where('company_code','MDMF')->count()],
        ]);
        $this->line('JFL: '.($items->firstWhere('code','JFL')['company_code'] ?? 'no PT mapping / code not present'));
        return self::SUCCESS;
    }
}
