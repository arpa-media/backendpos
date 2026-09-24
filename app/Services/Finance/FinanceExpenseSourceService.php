<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceExpenseSourceService
{
    public function postingRows(?string $companyCode, ?string $outletId, string $dateFrom, string $dateTo, ?string $marking = null): array
    {
        if (! Schema::hasTable('finance_expense_report_items') || ! Schema::hasTable('finance_expense_reports')) {
            return [];
        }

        $query = DB::table('finance_expense_report_items as i')
            ->join('finance_expense_reports as r', 'r.id', '=', 'i.report_id')
            ->whereBetween('r.business_date', [$dateFrom, $dateTo]);

        if ($companyCode) {
            $query->where('r.company_code', strtoupper($companyCode));
        }
        if ($outletId) {
            $query->where('r.outlet_id', $outletId);
        }
        if ($marking) {
            $query->where('i.marking', strtoupper($marking));
        }

        return $query->orderBy('r.business_date')->orderBy('i.entry_time')->get([
            'i.id', 'i.account_id', 'i.coa_code', 'i.coa_name', 'i.description', 'i.amount', 'i.marking',
            'r.id as report_id', 'r.business_date', 'r.company_code', 'r.outlet_id',
        ])->map(fn ($row) => [
            'source_type' => 'EXPENSE_REPORT',
            'source_id' => (string) $row->id,
            'source_key' => 'PETTY_CASH_EXPENSE_ITEM:'.(string) $row->id,
            'report_id' => (string) $row->report_id,
            'business_date' => (string) $row->business_date,
            'company_code' => $row->company_code ? (string) $row->company_code : null,
            'outlet_id' => (string) $row->outlet_id,
            'marking' => (string) $row->marking,
            'account_id' => $row->account_id ? (string) $row->account_id : null,
            'coa_code' => (string) ($row->coa_code ?? ''),
            'coa_name' => (string) ($row->coa_name ?? ''),
            'description' => (string) $row->description,
            'amount' => (float) $row->amount,
        ])->all();
    }
}
