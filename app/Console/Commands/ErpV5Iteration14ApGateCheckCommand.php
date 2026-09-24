<?php

namespace App\Console\Commands;

use App\Services\Purchasing\AccountPayableRealizationGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class ErpV5Iteration14ApGateCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-14-check {--invoice= : Inspect one AP invoice in detail}';
    protected $description = 'Validate ERP-V5 Iteration 14 AP realization completion gate contracts.';

    public function __construct(private readonly AccountPayableRealizationGateService $gate)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $root = base_path();
        $invoiceService = @file_get_contents($root.'/app/Services/Purchasing/InvoiceWorkflowService.php') ?: '';
        $apLifecycle = @file_get_contents($root.'/app/Services/Purchasing/OrderApLifecycleService.php') ?: '';
        $reimburse = @file_get_contents($root.'/app/Services/Purchasing/ReimbursePayableService.php') ?: '';
        $frontend = @file_get_contents(dirname($root).'/frontend - Backoffice/src/modules/purchasing/pages/PurchasingAccountLedgerPage.vue') ?: '';

        $checks = [
            'Table pur_invoices' => Schema::hasTable('pur_invoices'),
            'Table pur_invoice_payments' => Schema::hasTable('pur_invoice_payments'),
            'Table canonical AP lifecycle' => Schema::hasTable('pur_order_ap_lifecycles'),
            'Generic AP payment backend gate' => str_contains($invoiceService, 'assertInvoiceCanBePaid'),
            'Settlement realization-kind gate' => str_contains($apLifecycle, 'assertRealizationMaySettle'),
            'Legacy reimburse still requires POSTED RP' => str_contains($reimburse, "execution->status !== 'POSTED'"),
            'AP UI payment_eligibility' => str_contains($frontend, 'payment_eligibility') && str_contains($frontend, 'WAITING REALIZATION'),
            'AP ledger payment route' => Route::has('purchasing.ledger.account-payable.payment'),
            'Incoming invoice payment route' => Route::has('purchasing.invoice.incoming.payment'),
            'Access Matrix AP menu' => $this->accessMenuExists(),
        ];

        $this->table(['Check', 'Result'], collect($checks)->map(fn ($ok, $name) => [$name, $ok ? 'OK' : 'FAILED'])->values()->all());

        $dataWarnings = [];
        if (Schema::hasTable('pur_invoices')) {
            $query = DB::table('pur_invoices')->where('direction', 'INCOMING')->whereNull('deleted_at');
            if ($id = trim((string) $this->option('invoice'))) $query->where('id', $id);
            foreach ($query->orderByDesc('created_at')->limit($id ? 1 : 200)->get() as $invoice) {
                $eligibility = $this->gate->eligibilityForInvoice($invoice);
                if (($eligibility['realization_required'] ?? false) && ! ($eligibility['eligible'] ?? false) && (float) ($invoice->balance_due ?? 0) > 0.009) {
                    $payments = (int) DB::table('pur_invoice_payments')->where('invoice_id', $invoice->id)->where('status', 'POSTED')->count();
                    if ($payments > 0) {
                        $dataWarnings[] = (string) $invoice->invoice_number.' memiliki histori payment sementara gate saat ini blocked.';
                    }
                }
                if ($id) {
                    $this->newLine();
                    $this->line('Invoice            : '.(string) $invoice->invoice_number);
                    $this->line('Payment eligibility: '.json_encode($eligibility, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        if ($dataWarnings) {
            $this->newLine();
            $this->warn('Historical warning (tidak dimutasi otomatis):');
            foreach (array_slice($dataWarnings, 0, 20) as $warning) $this->line('- '.$warning);
            $this->line('Jalankan: php artisan erp-v5:iteration-14-reconcile --dry-run');
        }

        $failed = collect($checks)->contains(false);
        $this->newLine();
        $failed ? $this->error('Status: FAILED') : $this->info('Status: PASSED');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function accessMenuExists(): bool
    {
        if (! Schema::hasTable('access_menus')) return false;
        $query = DB::table('access_menus')->where('code', 'purchasing-account-payables');
        if (Schema::hasColumn('access_menus', 'path')) $query->where('path', '/purchasing/account-payables');
        return $query->exists();
    }
}
