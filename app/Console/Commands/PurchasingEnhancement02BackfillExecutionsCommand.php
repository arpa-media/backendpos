<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Purchasing\ExecutionWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement02BackfillExecutionsCommand extends Command
{
    protected $signature = 'purchasing:enhancement-02-backfill
        {--dry-run : Hanya tampilkan Order APPROVED yang belum mempunyai Execution aktif}
        {--kind=ALL : ALL|PURCHASE_ORDER|SERVICE_ORDER|REIMBURSE_ORDER}
        {--limit=0 : Batasi jumlah Order per jenis, 0 = tanpa batas}';

    protected $description = 'Backfill canonical Execution DRAFT untuk PO/SO/Reimburse Order yang sudah final APPROVED.';

    public function __construct(private readonly ExecutionWorkflowService $executions)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $requested = strtoupper(str_replace('-', '_', trim((string) $this->option('kind'))));
        $allowed = ['ALL', 'PURCHASE_ORDER', 'SERVICE_ORDER', 'REIMBURSE_ORDER'];
        if (! in_array($requested, $allowed, true)) {
            $this->error('Option --kind harus ALL, PURCHASE_ORDER, SERVICE_ORDER, atau REIMBURSE_ORDER.');
            return self::FAILURE;
        }

        $definitions = [
            'PURCHASE_ORDER' => ['table' => 'pur_purchase_orders', 'number' => 'po_number', 'execution' => 'pur_goods_receipts'],
            'SERVICE_ORDER' => ['table' => 'pur_service_orders', 'number' => 'service_order_number', 'execution' => 'pur_service_acceptances'],
            'REIMBURSE_ORDER' => ['table' => 'pur_reimburse_orders', 'number' => 'reimburse_order_number', 'execution' => 'pur_reimburse_payments'],
        ];
        if ($requested !== 'ALL') {
            $definitions = [$requested => $definitions[$requested]];
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $rows = [];
        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($definitions as $kind => $d) {
            if (! Schema::hasTable($d['table']) || ! Schema::hasTable($d['execution'])) {
                $rows[] = [$kind, '-', 'SKIPPED', 'Tabel belum tersedia'];
                continue;
            }

            $query = DB::table($d['table'])
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at');

            $sortColumn = $this->approvalSortColumn($d['table']);
            $query->orderBy($sortColumn)->orderBy('id');
            if ($limit > 0) {
                $query->limit($limit);
            }

            foreach ($query->get() as $order) {
                $active = $this->executions->executionForOrder($kind, (string) $order->id);
                if ($active) { $existing++; continue; }

                if ($dryRun) {
                    $rows[] = [$kind, (string) $order->{$d['number']}, 'MISSING', 'Akan dibuat DRAFT'];
                    continue;
                }

                try {
                    $actorId = $order->finance_approved_2_by_user_id
                        ?? $order->approved_by_user_id
                        ?? $order->updated_by_user_id
                        ?? null;
                    $actor = $actorId ? User::query()->find((string) $actorId) : null;
                    $result = $this->executions->ensureDraftFromApprovedOrder(
                        $kind,
                        (string) $order->id,
                        $actor,
                        'BACKFILL_APPROVED_ORDER'
                    );
                    $created += (($result['created'] ?? false) || ($result['restored'] ?? false)) ? 1 : 0;
                    $rows[] = [$kind, (string) $order->{$d['number']}, 'OK', ($result['number'] ?? '-').' · '.($result['status'] ?? '-')];
                } catch (\Throwable $e) {
                    $failed++;
                    $rows[] = [$kind, (string) $order->{$d['number']}, 'FAILED', $e->getMessage()];
                }
            }
        }

        if ($rows !== []) {
            $this->table(['Order Kind', 'Order', 'Result', 'Execution'], $rows);
        }

        $this->line(sprintf(
            'Dry run: %s | Existing active: %d | Created/restored: %d | Failed: %d',
            $dryRun ? 'YES' : 'NO',
            $existing,
            $created,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function approvalSortColumn(string $table): string
    {
        foreach ([
            'approved_at',
            'finance_approved_2_at',
            'finance_approved_1_at',
            'spv_approved_at',
            'order_date',
            'updated_at',
            'created_at',
        ] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }

}
