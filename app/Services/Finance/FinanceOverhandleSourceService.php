<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceOverhandleSourceService
{
    public function closingForReconciliation(string $outletId, string $businessDate): array
    {
        if (! Schema::hasTable('finance_overhandle_reports')) {
            return $this->empty($outletId, $businessDate);
        }

        $report = DB::table('finance_overhandle_reports')
            ->where('outlet_id', $outletId)
            ->where('business_date', '=', $businessDate)
            ->where('shift_type', 'closing')
            ->first();

        if (! $report) {
            return $this->empty($outletId, $businessDate);
        }

        $payments = Schema::hasTable('finance_overhandle_report_payments')
            ? DB::table('finance_overhandle_report_payments')
                ->where('report_id', $report->id)
                ->orderBy('sort_order')
                ->orderBy('payment_method_name')
                ->get()
            : collect();

        return [
            'has_closing' => true,
            'status' => 'AVAILABLE',
            'status_message' => 'Overhandle Report tersedia.',
            'report_id' => (string) $report->id,
            'business_date' => (string) $report->business_date,
            'outlet_id' => (string) $report->outlet_id,
            'company_code' => $report->company_code ? (string) $report->company_code : null,
            'snapshot_time' => substr((string) $report->snapshot_time, 0, 5),
            'pos_total' => (int) $report->tkj_pos_total,
            'actual_total' => (int) $report->actual_total,
            'variance_total' => (int) $report->difference_total,
            'discount_total' => (int) ($report->discount_total_at_snapshot ?? 0),
            'tax_total' => (int) ($report->tax_total_at_snapshot ?? 0),
            'rounding_total' => (int) ($report->rounding_total_at_snapshot ?? 0),
            'payment_methods' => $payments->map(fn ($row) => [
                'payment_method_id' => $row->payment_method_id ? (string) $row->payment_method_id : null,
                'payment_method' => (string) $row->payment_method_name,
                'pos_amount' => (int) $row->tkj_pos_amount,
                'actual_amount' => (int) $row->actual_amount,
                'variance_amount' => (int) $row->difference_amount,
                'note' => (string) ($row->note ?? ''),
            ])->values()->all(),
        ];
    }

    private function empty(string $outletId, string $businessDate): array
    {
        return [
            'has_closing' => false,
            'status' => 'MISSING',
            'status_message' => 'Belum ada Overhandle Report di tanggal terpilih.',
            'report_id' => null,
            'business_date' => $businessDate,
            'outlet_id' => $outletId,
            'company_code' => null,
            'snapshot_time' => null,
            'pos_total' => 0,
            'actual_total' => 0,
            'variance_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'payment_methods' => [],
        ];
    }
}
