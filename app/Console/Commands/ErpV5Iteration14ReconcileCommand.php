<?php

namespace App\Console\Commands;

use App\Services\Purchasing\AccountPayableRealizationGateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpV5Iteration14ReconcileCommand extends Command
{
    protected $signature = 'erp-v5:iteration-14-reconcile {--dry-run : Preview without writing metadata} {--invoice= : Limit to one Purchasing invoice ULID}';
    protected $description = 'Refresh AP realization-payment eligibility metadata without mutating financial/payment history.';

    public function __construct(private readonly AccountPayableRealizationGateService $gate)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach (['pur_invoices', 'pur_invoice_payments'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Table {$table} tidak tersedia.");
                return self::FAILURE;
            }
        }

        $query = DB::table('pur_invoices')
            ->where('direction', 'INCOMING')
            ->whereNull('deleted_at')
            ->orderBy('invoice_number');
        if ($id = trim((string) $this->option('invoice'))) $query->where('id', $id);

        $rows = $query->get();
        $dryRun = (bool) $this->option('dry-run');
        $report = [];
        $blocked = $eligible = $settled = $historicalReview = $updated = 0;

        foreach ($rows as $invoice) {
            $snapshot = $this->gate->metadataSnapshot($invoice);
            $status = (string) ($snapshot['status'] ?? 'UNKNOWN');
            if ($status === 'SETTLED') $settled++;
            elseif (($snapshot['eligible'] ?? false) === true) $eligible++;
            else $blocked++;

            $review = $this->historicalPaymentReview($invoice, $snapshot);
            if ($review !== null) $historicalReview++;

            if (! $dryRun) {
                $metadata = $this->decodeJson($invoice->metadata ?? null);
                $metadata['erp_v5_iteration_14_payment_gate'] = $snapshot;
                if ($review !== null) $metadata['erp_v5_iteration_14_historical_payment_review'] = $review;
                else unset($metadata['erp_v5_iteration_14_historical_payment_review']);
                DB::table('pur_invoices')->where('id', $invoice->id)->update([
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]); // financial/business timestamps and all payment fields remain immutable.
                $updated++;
            }

            if (count($report) < 50) {
                $realization = $snapshot['realization'] ?? null;
                $report[] = [
                    (string) $invoice->invoice_number,
                    (string) $status,
                    ($snapshot['eligible'] ?? false) ? 'YES' : 'NO',
                    is_array($realization) ? (($realization['number'] ?? '-') . ' / ' . ($realization['status'] ?? '-')) : '-',
                    $review ? 'REVIEW' : '-',
                ];
            }
        }

        $this->table(['Invoice', 'Gate', 'Eligible', 'Realization', 'Historical'], $report);
        $this->newLine();
        $this->line('Mode              : '.($dryRun ? 'DRY RUN' : 'APPLY METADATA'));
        $this->line('Invoice diperiksa : '.$rows->count());
        $this->line('Eligible          : '.$eligible);
        $this->line('Blocked           : '.$blocked);
        $this->line('Settled           : '.$settled);
        $this->line('Historical review : '.$historicalReview);
        if (! $dryRun) $this->line('Metadata updated  : '.$updated);
        $this->info('Tidak ada pur_invoice_payments, paid_amount, balance_due, journal, atau histori pembayaran yang dihapus/diubah.');

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function historicalPaymentReview(object $invoice, array $snapshot): ?array
    {
        if (($snapshot['realization_required'] ?? false) !== true) return null;

        $payment = DB::table('pur_invoice_payments')
            ->where('invoice_id', $invoice->id)
            ->where('status', 'POSTED')
            ->orderBy('posted_at')
            ->orderBy('created_at')
            ->first();
        if (! $payment) return null;

        $realization = $snapshot['realization'] ?? null;
        if (! is_array($realization) || ! in_array(strtoupper((string) ($realization['status'] ?? '')), ['POSTED', 'APPROVED'], true)) {
            return [
                'reason' => 'PAYMENT_EXISTS_BEFORE_REALIZATION_FINAL',
                'first_payment_id' => (string) $payment->id,
                'first_payment_number' => (string) $payment->payment_number,
                'first_payment_posted_at' => $payment->posted_at ?? $payment->created_at,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        $finalAt = $realization['final_at'] ?? null;
        $paymentAt = $payment->posted_at ?? $payment->created_at;
        if ($finalAt && $paymentAt && Carbon::parse($paymentAt)->lt(Carbon::parse($finalAt))) {
            return [
                'reason' => 'PAYMENT_POSTED_BEFORE_REALIZATION_FINAL_TIMESTAMP',
                'first_payment_id' => (string) $payment->id,
                'first_payment_number' => (string) $payment->payment_number,
                'first_payment_posted_at' => $paymentAt,
                'realization_kind' => $realization['kind'] ?? null,
                'realization_id' => $realization['id'] ?? null,
                'realization_number' => $realization['number'] ?? null,
                'realization_final_at' => $finalAt,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
