<?php

namespace App\Services\Cogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class CogsResetService
{
    private const CONFIRMATION = 'RESET COGS';

    public function __construct(
        private readonly PurchasingCostSnapshotService $purchasingSnapshots,
        private readonly WarehouseV3ValuationBridgeService $warehouseSnapshots,
        private readonly CogsValuationResolverService $valuationResolver,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(): array
    {
        $groups = [
            $this->group('Item Sold & Recipe Consumption', [
                'cogs_sale_consumption_items' => $this->count('cogs_sale_consumption_items'),
                'cogs_sale_consumptions' => $this->count('cogs_sale_consumptions'),
                'stk_inventory_movements (COGS only)' => $this->cogsMovementCount(),
            ]),
            $this->group('Stock Variance', [
                'cogs_stock_variance_items' => $this->count('cogs_stock_variance_items'),
                'cogs_stock_variances' => $this->count('cogs_stock_variances'),
            ]),
            $this->group('COGS Calculation', [
                'cogs_calculation_items' => $this->count('cogs_calculation_items'),
                'cogs_calculation_runs' => $this->count('cogs_calculation_runs'),
            ]),
            $this->group('COGS Cost Snapshot', [
                'cogs_purchasing_cost_snapshots' => $this->count('cogs_purchasing_cost_snapshots'),
            ]),
        ];

        $postedFinance = $this->blockingFinanceCogsCount();
        $draftFinance = $this->deletableDraftFinanceCogsCount();

        return [
            'confirmation_text' => self::CONFIRMATION,
            'total_rows' => array_sum(array_column($groups, 'rows')),
            'groups' => $groups,
            'blockers' => [
                'finance_cogs_posted_or_audited' => $postedFinance,
            ],
            'cleanup' => [
                'finance_cogs_clean_draft' => $draftFinance,
            ],
            'can_reset' => $postedFinance === 0,
            'pricing_policy' => 'Warehouse supplied SKU menggunakan harga jual Warehouse / invoice (Base UOM equivalent).',
            'warning' => $postedFinance > 0
                ? 'Reset diblokir karena ada COGS Posting Finance yang sudah POSTED atau memiliki audit journal. Reopen/reverse posting Finance terlebih dahulu dan pastikan audit posting sudah aman untuk reset.'
                : 'Reset menghapus hasil proses COGS saja. Recipe, UOM Conversion, Sales, Stock Opname, Actual Stock, GR, Purchasing dan Warehouse tidak dihapus. Snapshot cost akan dibangun ulang otomatis.',
        ];
    }

    /** @return array<string,mixed> */
    public function execute(string $confirmation, bool $acknowledge, ?string $userId = null): array
    {
        if (trim($confirmation) !== self::CONFIRMATION || ! $acknowledge) {
            throw ValidationException::withMessages([
                'confirmation' => ['Ketik RESET COGS dan centang konfirmasi untuk menjalankan reset.'],
            ]);
        }

        $postedFinance = $this->blockingFinanceCogsCount();
        if ($postedFinance > 0) {
            throw ValidationException::withMessages([
                'finance_cogs_posting' => ["Reset COGS diblokir: {$postedFinance} COGS Posting Finance sudah POSTED atau memiliki audit journal. Reopen/reverse dari Finance → COGS Posting terlebih dahulu."],
            ]);
        }

        $before = $this->preview();
        $deleted = DB::transaction(function (): array {
            $deleted = [
                'finance_cogs_draft' => 0,
                'inventory_movements' => 0,
                'calculation_runs' => 0,
                'stock_variances' => 0,
                'sale_consumptions' => 0,
                'purchasing_cost_snapshots' => 0,
            ];

            if (Schema::hasTable('finance_cogs_postings') && Schema::hasTable('cogs_calculation_runs')) {
                $runIds = DB::table('cogs_calculation_runs')->pluck('id');
                if ($runIds->isNotEmpty()) {
                    $deleted['finance_cogs_draft'] = DB::table('finance_cogs_postings')
                        ->whereIn('cogs_calculation_run_id', $runIds)
                        ->where('status', 'DRAFT')
                        ->when(Schema::hasTable('finance_cogs_posting_journals'), function ($query): void {
                            $query->whereNotExists(function ($sub): void {
                                $sub->selectRaw('1')
                                    ->from('finance_cogs_posting_journals as journal')
                                    ->whereColumn('journal.cogs_posting_id', 'finance_cogs_postings.id');
                            });
                        })
                        ->delete();
                }
            }

            if (Schema::hasTable('stk_inventory_movements')) {
                $deleted['inventory_movements'] = DB::table('stk_inventory_movements')
                    ->where(function ($query): void {
                        $query->where('reference_type', 'cogs_sale_consumption')
                            ->orWhereIn('movement_type', ['sale_consumption', 'sale_consumption_reversal']);
                    })
                    ->delete();
            }

            if (Schema::hasTable('cogs_calculation_runs')) {
                $deleted['calculation_runs'] = DB::table('cogs_calculation_runs')->delete();
            }
            if (Schema::hasTable('cogs_stock_variances')) {
                $deleted['stock_variances'] = DB::table('cogs_stock_variances')->delete();
            }
            if (Schema::hasTable('cogs_sale_consumptions')) {
                $deleted['sale_consumptions'] = DB::table('cogs_sale_consumptions')->delete();
            }
            if (Schema::hasTable('cogs_purchasing_cost_snapshots')) {
                $deleted['purchasing_cost_snapshots'] = DB::table('cogs_purchasing_cost_snapshots')->delete();
            }

            return $deleted;
        }, 5);

        $this->valuationResolver->clearCache();
        $rebuild = [
            'legacy_purchasing' => null,
            'warehouse_v3' => null,
            'warnings' => [],
        ];

        try {
            $rebuild['legacy_purchasing'] = $this->purchasingSnapshots->backfillReleasedReceipts();
        } catch (Throwable $exception) {
            $rebuild['warnings'][] = 'Backfill Purchasing cost snapshot: '.$exception->getMessage();
        }

        try {
            $rebuild['warehouse_v3'] = $this->warehouseSnapshots->reconcile(null, null, null, false);
        } catch (Throwable $exception) {
            $rebuild['warnings'][] = 'Backfill Warehouse V3 selling-price snapshot: '.$exception->getMessage();
        }

        $this->valuationResolver->clearCache();

        return [
            'message' => 'Reset COGS selesai. Data hasil proses dibersihkan dan cost snapshot dibangun ulang. Jalankan kembali Item Sold, Stock Variance, lalu COGS Calculation.',
            'pricing_policy' => 'warehouse_selling_price',
            'before' => $before,
            'deleted' => $deleted,
            'snapshot_rebuild' => $rebuild,
            'performed_by_user_id' => $userId,
            'performed_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /** @param array<string,int> $tables */
    private function group(string $name, array $tables): array
    {
        return [
            'group' => $name,
            'rows' => array_sum(array_values($tables)),
            'tables' => collect($tables)->map(fn (int $rows, string $table): array => [
                'table' => $table,
                'rows' => $rows,
            ])->values()->all(),
        ];
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }

    private function cogsMovementCount(): int
    {
        if (! Schema::hasTable('stk_inventory_movements')) return 0;
        return (int) DB::table('stk_inventory_movements')
            ->where(function ($query): void {
                $query->where('reference_type', 'cogs_sale_consumption')
                    ->orWhereIn('movement_type', ['sale_consumption', 'sale_consumption_reversal']);
            })
            ->count();
    }

    private function blockingFinanceCogsCount(): int
    {
        if (! Schema::hasTable('finance_cogs_postings')) return 0;
        return (int) DB::table('finance_cogs_postings')
            ->where(function ($query): void {
                $query->where('status', 'POSTED');
                if (Schema::hasTable('finance_cogs_posting_journals')) {
                    $query->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('finance_cogs_posting_journals as journal')
                            ->whereColumn('journal.cogs_posting_id', 'finance_cogs_postings.id');
                    });
                }
            })
            ->count();
    }

    private function deletableDraftFinanceCogsCount(): int
    {
        if (! Schema::hasTable('finance_cogs_postings')) return 0;
        return (int) DB::table('finance_cogs_postings')
            ->where('status', 'DRAFT')
            ->when(Schema::hasTable('finance_cogs_posting_journals'), function ($query): void {
                $query->whereNotExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('finance_cogs_posting_journals as journal')
                        ->whereColumn('journal.cogs_posting_id', 'finance_cogs_postings.id');
                });
            })
            ->count();
    }
}
