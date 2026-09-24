<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FinancePostingTemplateEngine
{
    public function __construct(private readonly FinanceFormulaEvaluator $formulaEvaluator)
    {
    }

    public function preview(?string $templateId, string $sourceType, array $context): array
    {
        $template = $this->resolveTemplate($templateId, $sourceType, $context);
        $lines = $this->buildLines((string) $template->id, $context);
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (count($lines) < 2) {
            throw new InvalidArgumentException('Template tidak menghasilkan minimal 2 baris jurnal.');
        }
        if ($debit <= 0 || $credit <= 0 || abs($debit - $credit) > 0.005) {
            throw new InvalidArgumentException('Hasil template tidak balance. Periksa formula debit/credit.');
        }

        return [
            'template' => [
                'id' => (string) $template->id,
                'code' => (string) $template->code,
                'name' => (string) $template->name,
                'source_type' => (string) $template->source_type,
                'company_code' => $template->company_code ? (string) $template->company_code : null,
                'outlet_id' => $template->outlet_id ? (string) $template->outlet_id : null,
                'marking' => $template->marking ? (string) $template->marking : null,
            ],
            'lines' => $lines,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balanced' => true,
        ];
    }

    public function resolveTemplate(?string $templateId, string $sourceType, array $context): object
    {
        foreach (['finance_posting_templates', 'finance_posting_template_lines', 'finance_chart_of_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException("Tabel {$table} belum tersedia. Jalankan migration Finance Iterasi 03.");
            }
        }

        $query = DB::table('finance_posting_templates')
            ->whereNull('deleted_at')
            ->where('is_active', true);

        if ($templateId) {
            $template = $query->where('id', $templateId)->first();
        } else {
            $sourceType = strtoupper(trim($sourceType));
            $companyCode = strtoupper(trim((string) ($context['company_code'] ?? '')));
            $outletId = trim((string) ($context['outlet_id'] ?? ''));
            $marking = strtoupper(trim((string) ($context['marking'] ?? '')));

            $template = $query
                ->where('source_type', $sourceType)
                ->where(function ($q) use ($companyCode): void {
                    $q->whereNull('company_code');
                    if ($companyCode !== '') {
                        $q->orWhere('company_code', $companyCode);
                    }
                })
                ->where(function ($q) use ($outletId): void {
                    $q->whereNull('outlet_id');
                    if ($outletId !== '') {
                        $q->orWhere('outlet_id', $outletId);
                    }
                })
                ->where(function ($q) use ($marking): void {
                    $q->whereNull('marking');
                    if ($marking !== '') {
                        $q->orWhere('marking', $marking);
                    }
                })
                ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN company_code IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN marking IS NULL THEN 1 ELSE 0 END')
                ->orderBy('code')
                ->first();
        }

        if (! $template) {
            throw new InvalidArgumentException('Template jurnal aktif tidak ditemukan untuk source/scope tersebut.');
        }

        return $template;
    }

    private function buildLines(string $templateId, array $context): array
    {
        $rows = DB::table('finance_posting_template_lines as l')
            ->join('finance_chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.template_id', $templateId)
            ->orderBy('l.sort_order')
            ->get([
                'l.*',
                'a.code as account_code', 'a.name as account_name', 'a.account_type', 'a.normal_balance',
                'a.is_active', 'a.is_postable',
            ]);

        if ($rows->count() < 2) {
            throw new InvalidArgumentException('Template jurnal minimal harus mempunyai 2 baris.');
        }

        $lines = [];
        foreach ($rows as $row) {
            if (! $row->is_active || ! $row->is_postable) {
                throw new InvalidArgumentException("COA {$row->account_code} pada template tidak aktif/postable.");
            }

            $amount = $this->formulaEvaluator->evaluate((string) $row->amount_formula, $context);
            if ($amount < 0) {
                throw new InvalidArgumentException('Formula template tidak boleh menghasilkan nilai negatif.');
            }
            if ($amount == 0.0) {
                continue;
            }

            $side = strtoupper((string) $row->side);
            if (! in_array($side, ['DEBIT', 'CREDIT'], true)) {
                throw new InvalidArgumentException('Side template harus DEBIT atau CREDIT.');
            }

            $lines[] = [
                'account_id' => (string) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'account_type' => (string) $row->account_type,
                'normal_balance' => (string) $row->normal_balance,
                'description' => $this->replaceMemoTokens((string) ($row->memo_template ?: ($context['description'] ?? '')), $context),
                'debit' => $side === 'DEBIT' ? $amount : 0.0,
                'credit' => $side === 'CREDIT' ? $amount : 0.0,
            ];
        }

        return $lines;
    }

    private function replaceMemoTokens(string $text, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $matches) use ($context): string {
            $value = data_get($context, $matches[1], '');
            return is_scalar($value) || $value === null ? (string) $value : '';
        }, $text);
    }
}
