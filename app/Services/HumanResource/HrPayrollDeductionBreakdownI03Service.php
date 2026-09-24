<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollSlip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iterasi 03 — read-only breakdown untuk potongan payroll "Lain-lain".
 *
 * Nominal other_deduction tetap menjadi source of truth perhitungan payroll.
 * Service ini hanya menelusuri sumber potongan yang sudah memiliki audit link,
 * khususnya Uniform I11, agar slip dapat menjelaskan potongannya tanpa mengubah
 * ledger, payroll snapshot, atau workflow Finance.
 */
final class HrPayrollDeductionBreakdownI03Service
{
    private const DEDUCTIONS = 'HR_uniform_payroll_deductions';
    private const LINES = 'HR_uniform_outbound_lines';
    private const ITEMS = 'HR_uniform_items';
    private const OUTBOUNDS = 'HR_uniform_outbounds';

    public function forSlip(HrPayrollSlip $slip): array
    {
        $id = (string) $slip->id;
        return $this->forSlipIds([$id], collect([$id => $slip]))[$id] ?? $this->emptyBreakdown($slip);
    }

    /**
     * @param array<int,string> $slipIds
     * @param Collection<string,HrPayrollSlip>|null $slipsById
     * @return array<string,array<string,mixed>>
     */
    public function forSlipIds(array $slipIds, ?Collection $slipsById = null): array
    {
        $slipIds = collect($slipIds)->map(fn ($id) => trim((string) $id))->filter()->unique()->values();
        if ($slipIds->isEmpty()) return [];

        $slipsById ??= HrPayrollSlip::query()->whereIn('id', $slipIds->all())->get()->keyBy(fn (HrPayrollSlip $s) => (string) $s->id);
        $result = [];
        foreach ($slipIds as $id) {
            $slip = $slipsById->get($id);
            if ($slip) $result[$id] = $this->emptyBreakdown($slip);
        }

        if (! $this->schemaReady()) return $result;

        $rows = DB::table(self::DEDUCTIONS.' as d')
            ->join(self::LINES.' as l', 'l.id', '=', 'd.outbound_line_id')
            ->join(self::ITEMS.' as i', 'i.id', '=', 'l.uniform_item_id')
            ->join(self::OUTBOUNDS.' as h', 'h.id', '=', 'd.outbound_id')
            ->whereIn('d.payroll_slip_id', $slipIds->all())
            ->whereIn('d.status', ['CLAIMED', 'SETTLED'])
            ->orderBy('d.payroll_slip_id')
            ->orderBy('h.outbound_date')
            ->orderBy('i.name')
            ->get([
                'd.id as deduction_id', 'd.payroll_slip_id', 'd.amount', 'd.status as deduction_status',
                'd.payroll_month', 'h.document_no', 'h.outbound_date', 'h.outbound_type',
                'l.id as outbound_line_id', 'l.quantity', 'l.squad_charge', 'l.size_charge',
                'i.id as uniform_item_id', 'i.code as item_code', 'i.name as item_name', 'i.item_kind', 'i.size',
            ]);

        foreach ($rows->groupBy(fn ($row) => (string) $row->payroll_slip_id) as $slipId => $group) {
            /** @var HrPayrollSlip|null $slip */
            $slip = $slipsById->get($slipId);
            if (! $slip) continue;

            $uniformItems = $group->map(function ($row): array {
                $qty = max(1, (int) $row->quantity);
                $amount = round((float) $row->amount, 2);
                $size = trim((string) ($row->size ?? ''));
                $name = trim((string) ($row->item_name ?? 'Uniform')) ?: 'Uniform';
                $displayName = $name.($size !== '' ? ' ('.$size.')' : '');

                return [
                    'source' => 'UNIFORM',
                    'deduction_id' => (string) $row->deduction_id,
                    'outbound_line_id' => (string) $row->outbound_line_id,
                    'uniform_item_id' => (string) $row->uniform_item_id,
                    'item_code' => (string) ($row->item_code ?? ''),
                    'item_name' => $name,
                    'size' => $size !== '' ? $size : null,
                    'quantity' => $qty,
                    'unit_deduction' => round($amount / $qty, 2),
                    'amount' => $amount,
                    'document_no' => (string) ($row->document_no ?? ''),
                    'outbound_date' => (string) ($row->outbound_date ?? ''),
                    'payroll_month' => (string) ($row->payroll_month ?? ''),
                    'status' => (string) ($row->deduction_status ?? ''),
                    'label' => $displayName.' × '.$qty,
                ];
            })->values()->all();

            $uniformTotal = round((float) collect($uniformItems)->sum('amount'), 2);
            $otherTotal = round(max(0, (float) $slip->other_deduction), 2);
            $unclassified = round(max(0, $otherTotal - $uniformTotal), 2);
            $details = $uniformItems;
            if ($unclassified > 0) {
                $details[] = [
                    'source' => 'OTHER',
                    'label' => 'Potongan lain / manual (tanpa rincian sumber)',
                    'amount' => $unclassified,
                    'note' => null,
                ];
            }

            $result[$slipId] = [
                'total' => $otherTotal,
                'uniform_total' => $uniformTotal,
                'unclassified_total' => $unclassified,
                'uniform_item_count' => count($uniformItems),
                'uniform_items' => $uniformItems,
                'details' => $details,
                'has_detail' => $details !== [],
                'is_balanced' => abs($otherTotal - ($uniformTotal + $unclassified)) < 0.01,
            ];
        }

        return $result;
    }

    private function emptyBreakdown(HrPayrollSlip $slip): array
    {
        $otherTotal = round(max(0, (float) $slip->other_deduction), 2);
        $details = [];
        if ($otherTotal > 0) {
            $details[] = [
                'source' => 'OTHER',
                'label' => 'Potongan lain / manual (tanpa rincian sumber)',
                'amount' => $otherTotal,
                'note' => null,
            ];
        }
        return [
            'total' => $otherTotal,
            'uniform_total' => 0.0,
            'unclassified_total' => $otherTotal,
            'uniform_item_count' => 0,
            'uniform_items' => [],
            'details' => $details,
            'has_detail' => $details !== [],
            'is_balanced' => true,
        ];
    }

    private function schemaReady(): bool
    {
        foreach ([self::DEDUCTIONS, self::LINES, self::ITEMS, self::OUTBOUNDS] as $table) {
            if (! Schema::hasTable($table)) return false;
        }
        return Schema::hasColumn(self::DEDUCTIONS, 'payroll_slip_id');
    }

}
