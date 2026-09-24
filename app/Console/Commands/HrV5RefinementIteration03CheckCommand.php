<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration03CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i03-check';
    protected $description = 'Verify HR v5 Iteration 03 payroll/bonus PDF email patch.';

    public function handle(): int
    {
        $checks = [
            'Email log table' => Schema::hasTable('HR_payroll_slip_email_logs'),
            'Email log idempotency key' => Schema::hasTable('HR_payroll_slip_email_logs') && Schema::hasColumn('HR_payroll_slip_email_logs', 'document_key'),
            'Payroll email permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.payroll.cutoff.email')->exists(),
            'Bonus email permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.bonus.projection.email')->exists(),
            'Cutoff Access Matrix exists' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-payroll-cutoff')->exists(),
            'Bonus Access Matrix exists' => Schema::hasTable('access_menus') && DB::table('access_menus')->whereIn('code', ['hr-bonus-projection','hr-bonus-cutoff','hr-payroll-bonus-cutoff'])->exists(),
            'Payroll send route' => Route::has('hr.payroll.i03.slip.email'),
            'Bonus send route' => Route::has('hr.bonus.i03.slip.email'),
            'Payroll PDF route' => Route::has('hr.payroll.i03.slip.pdf'),
            'Bonus PDF route' => Route::has('hr.bonus.i03.slip.pdf'),
            'Standalone Logo HR payroll I03' => is_file(base_path('storage/app/hr/payroll-i03/branding/logo-hr.png')),
            'SMTP host default' => (string) config('hr_mail.host') === 'mail.tokokopijaya.com',
            'SMTP port default' => (int) config('hr_mail.port') === 465,
            'HR sender default' => (string) config('hr_mail.from_address') === 'ch.hr@tokokopijaya.com',
        ];
        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }
        $secret = (string) config('hr_mail.password');
        $this->line(($secret !== '' ? '<info>[OK]</info> ' : '<comment>[WARN]</comment> ').'HR_MAIL_PASSWORD '.($secret !== '' ? 'configured' : 'belum diisi; email tidak akan dikirim sampai env diisi'));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
