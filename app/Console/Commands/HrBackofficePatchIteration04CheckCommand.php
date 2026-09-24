<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration04CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i04-check';
    protected $description = 'Smoke-check HR Backoffice Patch Iteration 04 bulk payroll/bonus slip delivery.';

    public function handle(): int
    {
        $frontendRoot = realpath(base_path('../frontend - Backoffice')) ?: base_path('../frontend - Backoffice');
        $page = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceBulkSlipDeliveryI04Page.vue');
        $api = (string) @file_get_contents($frontendRoot.'/src/lib/humanResourceBulkSlipDeliveryI04Api.js');
        $routeModule = (string) @file_get_contents($frontendRoot.'/src/modules/human-resource/route-modules/40-bulk-slip-delivery-i04.js');
        $sidebarModule = (string) @file_get_contents($frontendRoot.'/src/modules/sidebar-menu-modules/modules/12-human-resource-bulk-slip-delivery-i04.js');

        $payrollMenu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-payroll-slip-bulk-i04')->first()
            : null;
        $bonusMenu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-bonus-slip-bulk-i04')->first()
            : null;

        $checks = [
            'Slip email log baseline tersedia' => Schema::hasTable('HR_payroll_slip_email_logs'),
            'Single payroll send route tersedia' => Route::has('hr.payroll.i03.slip.email'),
            'Single bonus send route tersedia' => Route::has('hr.bonus.i03.slip.email'),
            'Access Matrix Bulk Slip Gaji terpasang' => $payrollMenu
                && (string) $payrollMenu->permission_view === 'hr.payroll.cutoff.view'
                && (string) $payrollMenu->permission_create === 'hr.payroll.cutoff.email',
            'Access Matrix Bulk Slip Bonus terpasang' => $bonusMenu
                && (string) $bonusMenu->permission_view === 'hr.bonus.projection.view'
                && (string) $bonusMenu->permission_create === 'hr.bonus.projection.email',
            'Bulk page memakai concurrency terbatas' => str_contains($page, 'runPool(rows, 2')
                && str_contains($page, 'Kirim Terpilih'),
            'Bulk page tidak selectable ulang jika sent' => str_contains($page, "status === 'sent'")
                && str_contains($page, 'isEligible'),
            'Bulk API reuse endpoint single-send existing' => str_contains($api, 'sendPayrollSlipEmail')
                && str_contains($api, 'sendBonusSlipEmail'),
            'Route module additive terpasang' => str_contains($routeModule, 'human-resource-payroll-bulk-slip-i04')
                && str_contains($routeModule, 'human-resource-bonus-bulk-slip-i04'),
            'Sidebar module memasukkan action ke Cutoff' => str_contains($sidebarModule, "'hr-cutoff'")
                && str_contains($sidebarModule, 'Kirim Slip Gaji Bulk')
                && str_contains($sidebarModule, 'Kirim Slip Bonus Bulk'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! (bool) $ok;
        }

        $this->newLine();
        $this->line('<info>[INFO]</info> I04 sengaja tidak membuat endpoint bulk SMTP baru. Frontend mengorkestrasi endpoint single-send existing dengan concurrency=2 agar request tidak memegang puluhan SMTP delivery sekaligus.');
        $this->line('<info>[INFO]</info> Document key/log I03 tetap menjadi idempotency source of truth; recipient berstatus sent otomatis tidak eligible.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
