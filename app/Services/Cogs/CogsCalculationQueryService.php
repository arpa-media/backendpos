<?php

namespace App\Services\Cogs;

use App\Models\Cogs\CogsCalculationRun;
use Illuminate\Support\Facades\DB;

class CogsCalculationQueryService
{
    public function overview(string $outletId, string $from, string $to): array
    {
        $summary = DB::table('cogs_calculation_runs')
            ->where('outlet_id', $outletId)
            ->where('period_from', '>=', $from)
            ->where('period_to', '<=', $to)
            ->where('status', '!=', CogsCalculationRun::STATUS_CANCELLED)
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_runs")
            ->selectRaw('COALESCE(SUM(final_cogs_value), 0) as final_cogs')
            ->selectRaw('COALESCE(SUM(net_sales_value), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(gross_profit_value), 0) as gross_profit')
            ->selectRaw('COALESCE(SUM(ABS(reconciliation_difference)), 0) as reconciliation_difference')
            ->selectRaw('COALESCE(SUM(attention_count), 0) as attention_count')
            ->first();

        $latest = CogsCalculationRun::query()
            ->where('outlet_id', $outletId)
            ->where('period_from', '=', $from)
            ->where('period_to', '=', $to)
            ->first();

        return [
            'period' => ['date_from' => $from, 'date_to' => $to],
            'cards' => [
                'runs' => (int) ($summary->runs ?? 0),
                'closed_runs' => (int) ($summary->closed_runs ?? 0),
                'final_cogs' => $this->decimal((float) ($summary->final_cogs ?? 0), 2),
                'net_sales' => $this->decimal((float) ($summary->net_sales ?? 0), 2),
                'gross_profit' => $this->decimal((float) ($summary->gross_profit ?? 0), 2),
                'reconciliation_difference' => $this->decimal((float) ($summary->reconciliation_difference ?? 0), 2),
                'attention_count' => (int) ($summary->attention_count ?? 0),
            ],
            'current_run' => $latest ? $this->header($latest) : null,
        ];
    }

    public function paginate(string $outletId, array $filters): array
    {
        $query = CogsCalculationRun::query()
            ->where('outlet_id', $outletId)
            ->where('period_from', '>=', $filters['date_from'])
            ->where('period_to', '<=', $filters['date_to']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', CogsCalculationRun::STATUS_CANCELLED);
        }

        $paginator = $query
            ->orderByDesc('period_to')
            ->orderByDesc('calculated_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => collect($paginator->items())->map(fn (CogsCalculationRun $run) => $this->header($run))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function detail(string $id, string $outletId): ?array
    {
        $run = CogsCalculationRun::query()
            ->with([
                'outlet:id,code,name,timezone',
                'openingVariance:id,variance_date,status',
                'closingVariance:id,variance_date,status',
                'calculatedBy:id,name,nisj',
                'reconciledBy:id,name,nisj',
                'closedBy:id,name,nisj',
                'cancelledBy:id,name,nisj',
                'items',
            ])
            ->where('outlet_id', $outletId)
            ->find($id);

        if (! $run) {
            return null;
        }

        return [
            ...$this->header($run),
            'outlet' => $run->outlet ? [
                'id' => (string) $run->outlet->id,
                'code' => (string) ($run->outlet->code ?? ''),
                'name' => (string) $run->outlet->name,
                'timezone' => (string) ($run->outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            ] : null,
            'source_fingerprint' => (string) $run->source_fingerprint,
            'source_snapshot' => $run->source_snapshot ?: [],
            'reconciliation_checks' => $run->reconciliation_checks ?: [],
            'metadata' => $run->metadata ?: [],
            'calculated_by' => $this->user($run->calculatedBy),
            'reconciled_by' => $this->user($run->reconciledBy),
            'closed_by' => $this->user($run->closedBy),
            'cancelled_by' => $this->user($run->cancelledBy),
            'items' => $run->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) ($item->sku_code_snapshot ?? ''),
                'sku_name' => (string) $item->sku_name_snapshot,
                'base_uom_code' => (string) ($item->base_uom_code_snapshot ?? ''),
                'base_uom_symbol' => (string) ($item->base_uom_symbol_snapshot ?? $item->base_uom_code_snapshot ?? ''),
                'opening_quantity' => (string) $item->opening_quantity,
                'opening_unit_cost' => (string) $item->opening_unit_cost,
                'opening_value' => (string) $item->opening_value,
                'receipt_quantity' => (string) $item->receipt_quantity,
                'receipt_value' => (string) $item->receipt_value,
                'consumption_quantity' => (string) $item->consumption_quantity,
                'consumption_value' => (string) $item->consumption_value,
                'reversal_quantity' => (string) $item->reversal_quantity,
                'reversal_value' => (string) $item->reversal_value,
                'net_consumption_quantity' => (string) $item->net_consumption_quantity,
                'net_consumption_value' => (string) $item->net_consumption_value,
                'other_movement_quantity' => (string) $item->other_movement_quantity,
                'other_movement_value' => (string) $item->other_movement_value,
                'closing_quantity' => (string) $item->closing_quantity,
                'closing_unit_cost' => (string) $item->closing_unit_cost,
                'closing_value' => (string) $item->closing_value,
                'variance_quantity' => (string) $item->variance_quantity,
                'variance_value' => (string) $item->variance_value,
                'final_cogs_value' => (string) $item->final_cogs_value,
                'inventory_bridge_cogs_value' => (string) $item->inventory_bridge_cogs_value,
                'reconciliation_difference' => (string) $item->reconciliation_difference,
                'warning_codes' => $item->warning_codes ?: [],
                'trace' => $item->trace_snapshot ?: [],
            ])->all(),
        ];
    }

    public function csvRows(string $outletId, array $filters): iterable
    {
        return DB::table('cogs_calculation_items as item')
            ->join('cogs_calculation_runs as run', 'run.id', '=', 'item.calculation_run_id')
            ->where('run.outlet_id', $outletId)
            ->where('run.period_from', '>=', $filters['date_from'])
            ->where('run.period_to', '<=', $filters['date_to'])
            ->where('run.status', '!=', CogsCalculationRun::STATUS_CANCELLED)
            ->orderBy('run.period_from')
            ->orderBy('item.sku_name_snapshot')
            ->cursor()
            ->map(fn ($row) => [
                $row->period_from,
                $row->period_to,
                $row->status,
                $row->sku_code_snapshot,
                $row->sku_name_snapshot,
                $row->base_uom_code_snapshot,
                $row->opening_quantity,
                $row->opening_value,
                $row->receipt_quantity,
                $row->receipt_value,
                $row->net_consumption_quantity,
                $row->net_consumption_value,
                $row->other_movement_quantity,
                $row->other_movement_value,
                $row->closing_quantity,
                $row->closing_value,
                $row->variance_quantity,
                $row->variance_value,
                $row->final_cogs_value,
                $row->inventory_bridge_cogs_value,
                $row->reconciliation_difference,
                $row->warning_codes,
            ]);
    }

    public function header(CogsCalculationRun $run): array
    {
        return [
            'id' => (string) $run->id,
            'period_from' => $run->period_from?->toDateString(),
            'period_to' => $run->period_to?->toDateString(),
            'status' => (string) $run->status,
            'opening_variance_id' => $run->opening_variance_id ? (string) $run->opening_variance_id : null,
            'closing_variance_id' => $run->closing_variance_id ? (string) $run->closing_variance_id : null,
            'opening_snapshot_date' => $run->opening_snapshot_date?->toDateString(),
            'closing_snapshot_date' => $run->closing_snapshot_date?->toDateString(),
            'sales_transaction_count' => (int) $run->sales_transaction_count,
            'sale_item_line_count' => (int) $run->sale_item_line_count,
            'item_sold_quantity' => (string) $run->item_sold_quantity,
            'net_sales_value' => (string) $run->net_sales_value,
            'stock_request_document_count' => (int) $run->stock_request_document_count,
            'stock_request_line_count' => (int) $run->stock_request_line_count,
            'requested_quantity' => (string) $run->requested_quantity,
            'approved_quantity' => (string) $run->approved_quantity,
            'goods_receipt_document_count' => (int) $run->goods_receipt_document_count,
            'goods_receipt_line_count' => (int) $run->goods_receipt_line_count,
            'received_quantity' => (string) $run->received_quantity,
            'purchasing_value' => (string) $run->purchasing_value,
            'net_recipe_cogs_value' => (string) $run->net_recipe_cogs_value,
            'shortage_value' => (string) $run->shortage_value,
            'surplus_value' => (string) $run->surplus_value,
            'net_variance_value' => (string) $run->net_variance_value,
            'variance_adjustment_value' => (string) $run->variance_adjustment_value,
            'opening_inventory_value' => (string) $run->opening_inventory_value,
            'closing_inventory_value' => (string) $run->closing_inventory_value,
            'other_movement_value' => (string) $run->other_movement_value,
            'inventory_bridge_cogs_value' => (string) $run->inventory_bridge_cogs_value,
            'final_cogs_value' => (string) $run->final_cogs_value,
            'reconciliation_difference' => (string) $run->reconciliation_difference,
            'gross_profit_value' => (string) $run->gross_profit_value,
            'cogs_ratio_percent' => (string) $run->cogs_ratio_percent,
            'open_exception_count' => (int) $run->open_exception_count,
            'zero_cost_consumption_count' => (int) $run->zero_cost_consumption_count,
            'untraced_receipt_count' => (int) $run->untraced_receipt_count,
            'variance_attention_count' => (int) $run->variance_attention_count,
            'attention_count' => (int) $run->attention_count,
            'data_quality_score' => (int) $run->data_quality_score,
            'calculated_at' => optional($run->calculated_at)->toIso8601String(),
            'reconciled_at' => optional($run->reconciled_at)->toIso8601String(),
            'closed_at' => optional($run->closed_at)->toIso8601String(),
            'close_notes' => $run->close_notes,
            'cancelled_at' => optional($run->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $run->cancellation_reason,
        ];
    }

    private function user($user): ?array
    {
        if (! $user) return null;
        return [
            'id' => (string) $user->id,
            'name' => (string) ($user->name ?? $user->nisj ?? '-'),
            'nisj' => (string) ($user->nisj ?? ''),
        ];
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
