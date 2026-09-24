<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration11CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i11-check';
    protected $description = 'Validate HR V5 Refinement I11 Uniform outbound, payroll deduction and recap integration.';

    public function handle(): int
    {
        $checks = [
            'I10 Uniform master table' => Schema::hasTable('HR_uniform_items'),
            'I10 Uniform movement ledger' => Schema::hasTable('HR_uniform_movements'),
            'I11 outbound header table' => Schema::hasTable('HR_uniform_outbounds'),
            'I11 outbound line table' => Schema::hasTable('HR_uniform_outbound_lines'),
            'I11 payroll deduction table' => Schema::hasTable('HR_uniform_payroll_deductions'),
            'Payroll cutoffs available' => Schema::hasTable('HR_payroll_cutoffs'),
            'Payroll slips available' => Schema::hasTable('HR_payroll_slips'),
            'Canonical I11 workbook available' => is_file(storage_path('app/hr/templates/i11/TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx')),
            'Outbound service available' => class_exists(\App\Services\HumanResource\HrUniformOutboundI11Service::class),
            'Payroll deduction service available' => class_exists(\App\Services\HumanResource\HrUniformPayrollDeductionI11Service::class),
            'XLSX service available' => class_exists(\App\Services\HumanResource\HrUniformI11XlsxService::class),
        ];

        if (Schema::hasTable('permissions')) {
            foreach (['hr.uniform.outbound.view','hr.uniform.outbound.create','hr.uniform.attribute_outbound.view','hr.uniform.attribute_outbound.create'] as $permission) {
                $checks['Permission '.$permission] = DB::table('permissions')->where('name', $permission)->exists();
            }
        }
        if (Schema::hasTable('access_menus')) {
            $checks['Access Matrix Uniform Keluar'] = DB::table('access_menus')->where('code','hr-uniform-outbound')->where('path','/human-resource/manage-uniform/outbound')->exists();
            $checks['Access Matrix Atribut Keluar'] = DB::table('access_menus')->where('code','hr-uniform-attribute-outbound')->where('path','/human-resource/manage-uniform/attribute-outbound')->exists();
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $ok ? $this->info('[PASS] '.$label) : $this->error('[FAIL] '.$label);
            $failed = $failed || ! $ok;
        }

        if (Schema::hasTable('HR_uniform_payroll_deductions')) {
            $invalid = DB::table('HR_uniform_payroll_deductions')->whereNotIn('status',['PENDING','CLAIMED','SETTLED'])->count();
            $this->line('Deduction invalid status rows: '.$invalid);
            if ($invalid > 0) $failed = true;
        }
        if (Schema::hasTable('HR_uniform_stock_balances')) {
            $negative = DB::table('HR_uniform_stock_balances')->where('current_qty','<',0)->count();
            $this->line('Negative Uniform balances: '.$negative);
            if ($negative > 0) $failed = true;
        }

        $this->newLine();
        if ($failed) {
            $this->error('HR V5 Refinement I11 check FAILED.');
            return self::FAILURE;
        }
        $this->info('HR V5 Refinement I11 check PASSED.');
        return self::SUCCESS;
    }
}
