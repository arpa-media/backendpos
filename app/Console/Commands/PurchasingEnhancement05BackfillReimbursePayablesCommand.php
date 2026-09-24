<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Purchasing\ReimbursePayableService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement05BackfillReimbursePayablesCommand extends Command
{
    protected $signature = 'purchasing:enhancement-05-backfill-reimburse-payables {--dry-run} {--limit=0}';
    protected $description = 'Backfill Reimburse AP WAITING_PAYMENT untuk Reimburse Payment lama yang sudah POSTED.';

    public function __construct(private readonly ReimbursePayableService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('pur_reimburse_payments') || ! Schema::hasTable('pur_reimburse_payables')) {
            $this->error('Tabel PE05 belum tersedia. Jalankan migration terlebih dahulu.');
            return self::FAILURE;
        }

        $query = DB::table('pur_reimburse_payments as rp')
            ->where('rp.status', 'POSTED')
            ->whereNull('rp.deleted_at')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('pur_reimburse_payables as p')->whereColumn('p.reimburse_payment_id', 'rp.id');
            })
            ->orderBy('rp.document_date')->orderBy('rp.id');
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) $query->limit($limit);

        $rows = $query->get();
        $table = [];
        $failed = 0;
        foreach ($rows as $row) {
            if ($this->option('dry-run')) {
                $table[] = [(string) $row->payment_number, (string) $row->document_date, number_format((float) ($row->actual_total_amount ?? 0), 2, ',', '.'), 'MISSING'];
                continue;
            }
            try {
                $actor = $row->posted_by_user_id ? User::query()->find((string) $row->posted_by_user_id) : null;
                $created = $this->service->ensureFromExecution((string) $row->id, $actor);
                $table[] = [(string) $row->payment_number, (string) $row->document_date, number_format((float) ($row->actual_total_amount ?? 0), 2, ',', '.'), $created['payable_number'].' · '.$created['status']];
            } catch (\Throwable $e) {
                $failed++;
                $table[] = [(string) $row->payment_number, (string) $row->document_date, '-', 'FAILED: '.$e->getMessage()];
            }
        }

        if ($table !== []) $this->table(['Reimburse Payment', 'Date', 'Actual', 'Result'], $table);
        $this->line('Candidate: '.$rows->count().' | Failed: '.$failed.' | Dry-run: '.($this->option('dry-run') ? 'YES' : 'NO'));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
