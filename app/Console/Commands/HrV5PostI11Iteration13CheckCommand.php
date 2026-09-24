<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI11Iteration13CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i11-i13-check';
    protected $description = 'Verify HR post-I11 Iteration 13 email delivery diagnostics and status modal patch.';

    public function handle(): int
    {
        $service = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollSlipEmailService.php'));
        $controller = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/HumanResource/HrPayrollSlipEmailController.php'));
        $routes = (string) @file_get_contents(base_path('routes/hr_modules/20-payroll-slip-email.php'));
        $api = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/humanResourcePayrollEmailApi.js'));
        $modal = (string) @file_get_contents(base_path('../frontend - Backoffice/src/components/human-resource/HrMailDeliveryModalI13.vue'));
        $payroll = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourcePayrollCutoffPage.vue'));
        $bonus = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceBonusCutoffPlaceholderPage.vue'));

        $checks = [
            'Email log table from I03' => Schema::hasTable('HR_payroll_slip_email_logs'),
            'Email metadata column available' => Schema::hasTable('HR_payroll_slip_email_logs') && Schema::hasColumn('HR_payroll_slip_email_logs', 'metadata'),
            'I13 diagnostic route registered' => Route::has('hr.payroll.i13.mail.diagnostics'),
            'I13 diagnostic controller action' => str_contains($controller, 'function diagnostics'),
            'Structured failure response includes data' => str_contains($controller, "'HR_SMTP_DELIVERY_FAILED'") || str_contains($controller, "error_code"),
            'Runtime mailer cache purge' => str_contains($service, "purge('hr')"),
            'SMTP 465 scheme normalization' => str_contains($service, '$port === 465') && str_contains($service, "'smtps'"),
            'Safe error redaction' => str_contains($service, '[REDACTED]') && str_contains($service, 'safeTransportText'),
            'Active SMTP auth probe' => str_contains($service, 'getSymfonyTransport') && str_contains($service, '->start()'),
            'No SMTP password returned in context' => ! str_contains($service, "'password' => (string) config('hr_mail.password')") || str_contains($service, "'password_configured'"),
            'Frontend diagnostic API' => str_contains($api, 'diagnoseHrSlipMailer'),
            'Frontend failure parser' => str_contains($api, 'hrMailDeliveryFailure'),
            'Shared I13 delivery modal' => str_contains($modal, 'Human Resource Mail Delivery') && str_contains($modal, 'Tes SMTP'),
            'Payroll page uses modal' => str_contains($payroll, 'HrMailDeliveryModalI13') && str_contains($payroll, 'openMailDelivery'),
            'Bonus page uses modal' => str_contains($bonus, 'HrMailDeliveryModalI13') && str_contains($bonus, 'openMailDelivery'),
            'Route keeps payroll permission fallback' => str_contains($routes, 'hr.payroll.cutoff.email,hr.payroll.cutoff.update'),
            'Route keeps bonus permission fallback' => str_contains($routes, 'hr.bonus.projection.email,hr.bonus.projection.recalculate,hr.bonus.projection.submit'),
            'SMTP host configured' => trim((string) config('hr_mail.host')) !== '',
            'SMTP port valid' => (int) config('hr_mail.port') > 0,
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $passwordConfigured = trim((string) config('hr_mail.password')) !== '';
        $this->line(($passwordConfigured ? '<info>[OK]</info> ' : '<comment>[WARN]</comment> ').'HR_MAIL_PASSWORD '.($passwordConfigured ? 'configured' : 'belum diisi; isi di environment server sebelum tes/pengiriman SMTP'));
        $this->line('<comment>[INFO]</comment> Untuk active test gunakan tombol "Tes SMTP" pada modal pengiriman; checker tidak mengirim koneksi keluar secara otomatis.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
