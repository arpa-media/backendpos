<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceReconciliationService
{
    public function __construct(
        private readonly FinanceReconciliationSourceService $sourceService,
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly FinanceScopeResolver $financeScope,
    ) {
    }

    public function createOrLoadDraft(string $outletId, string $businessDate, ?string $userId): string
    {
        $existing = DB::table('finance_reconciliations')
            ->where('outlet_id', $outletId)
            ->where('business_date', '=', $businessDate)
            ->first();
        if ($existing && (string) $existing->status !== 'CANCELLED') {
            return (string) $existing->id;
        }

        $source = $this->sourceService->build($outletId, $businessDate);
        if (! $source['company_code']) {
            throw new InvalidArgumentException('Outlet belum dipetakan ke PT BKJB/MDMF pada Chart of Account → Mapping PT Outlet.');
        }

        return DB::transaction(function () use ($source, $userId, $existing): string {
            $id = $existing ? (string) $existing->id : (string) Str::ulid();
            $now = now();
            if ($existing) {
                DB::table('finance_reconciliations')->where('id',$id)->update([
                    'status'=>'DRAFT','company_code'=>$source['company_code'],'overhandle_report_id'=>$source['overhandle']['report_id']??null,
                    'has_overhandle'=>(bool)($source['overhandle']['has_closing']??false),'pos_total'=>$source['cashier_summary']['pos_total'],
                    'overhandle_total'=>$source['cashier_summary']['overhandle_total'],'effective_actual_total'=>0,
                    'pos_discount_total'=>$source['cashier_summary']['discount_total'],'discount_override_amount'=>null,'effective_discount_total'=>$source['cashier_summary']['discount_total'],
                    'pos_tax_total'=>$source['cashier_summary']['tax_total'],'tax_override_amount'=>null,'effective_tax_total'=>$source['cashier_summary']['tax_total'],
                    'pos_rounding_total'=>$source['cashier_summary']['rounding_total'],'rounding_override_amount'=>null,'effective_rounding_total'=>$source['cashier_summary']['rounding_total'],
                    'unresolved_payment_count'=>0,'source_fingerprint'=>$source['source_fingerprint'],'source_snapshot'=>json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    'note'=>null,'posted_at'=>null,'posted_by_user_id'=>null,'updated_by_user_id'=>$userId,'updated_at'=>$now,
                ]);
                $this->replaceSourceRows($id,$source,[],$userId);
                $this->recalculate($id,$userId);
                return $id;
            }
            DB::table('finance_reconciliations')->insert([
                'id' => $id,
                'reconciliation_no' => $this->nextNumber((string) $source['business_date']),
                'business_date' => $source['business_date'],
                'company_code' => $source['company_code'],
                'outlet_id' => $source['outlet_id'],
                'status' => 'DRAFT',
                'overhandle_report_id' => $source['overhandle']['report_id'] ?? null,
                'has_overhandle' => (bool) ($source['overhandle']['has_closing'] ?? false),
                'pos_total' => $source['cashier_summary']['pos_total'],
                'overhandle_total' => $source['cashier_summary']['overhandle_total'],
                'effective_actual_total' => 0,
                'pos_discount_total' => $source['cashier_summary']['discount_total'],
                'discount_override_amount' => null,
                'effective_discount_total' => $source['cashier_summary']['discount_total'],
                'pos_tax_total' => $source['cashier_summary']['tax_total'],
                'tax_override_amount' => null,
                'effective_tax_total' => $source['cashier_summary']['tax_total'],
                'pos_rounding_total' => $source['cashier_summary']['rounding_total'],
                'rounding_override_amount' => null,
                'effective_rounding_total' => $source['cashier_summary']['rounding_total'],
                'unresolved_payment_count' => 0,
                'source_fingerprint' => $source['source_fingerprint'],
                'source_snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'posting_version' => 0,
                'note' => null,
                'posted_at' => null,
                'posted_by_user_id' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceSourceRows($id, $source, [], $userId);
            $this->recalculate($id, $userId);
            return $id;
        });
    }

    public function refreshSource(string $id, ?string $userId): void
    {
        $entry = DB::table('finance_reconciliations')->where('id', $id)->first();
        if (! $entry) throw new InvalidArgumentException('Reconciliation tidak ditemukan.');
        if ($entry->status !== 'DRAFT') throw new InvalidArgumentException('Source hanya dapat direfresh saat status DRAFT. Reopen dahulu bila sudah POSTED.');

        $existingOverrides = DB::table('finance_reconciliation_payments')
            ->where('reconciliation_id', $id)
            ->get()
            ->keyBy(fn ($row) => mb_strtolower((string) $row->payment_method_name))
            ->map(fn ($row) => $row->actual_override_amount === null ? null : (float) $row->actual_override_amount)
            ->all();
        $source = $this->sourceService->build((string) $entry->outlet_id, (string) $entry->business_date);

        DB::transaction(function () use ($id, $source, $existingOverrides, $userId): void {
            DB::table('finance_reconciliations')->where('id', $id)->update([
                'company_code' => $source['company_code'],
                'overhandle_report_id' => $source['overhandle']['report_id'] ?? null,
                'has_overhandle' => (bool) ($source['overhandle']['has_closing'] ?? false),
                'pos_total' => $source['cashier_summary']['pos_total'],
                'overhandle_total' => $source['cashier_summary']['overhandle_total'],
                'pos_discount_total' => $source['cashier_summary']['discount_total'],
                'pos_tax_total' => $source['cashier_summary']['tax_total'],
                'pos_rounding_total' => $source['cashier_summary']['rounding_total'],
                'source_fingerprint' => $source['source_fingerprint'],
                'source_snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            $this->replaceSourceRows($id, $source, $existingOverrides, $userId);
            $this->recalculate($id, $userId);
        });
    }

    public function updateOverrides(string $id, array $payload, ?string $userId): void
    {
        $entry = DB::table('finance_reconciliations')->where('id', $id)->first();
        if (! $entry) throw new InvalidArgumentException('Reconciliation tidak ditemukan.');
        if ($entry->status !== 'DRAFT') throw new InvalidArgumentException('Reconciliation POSTED tidak dapat diedit. Reopen dahulu untuk koreksi.');

        DB::transaction(function () use ($id, $payload, $userId): void {
            DB::table('finance_reconciliations')->where('id', $id)->update([
                'discount_override_amount' => array_key_exists('discount_override_amount', $payload) ? $payload['discount_override_amount'] : DB::raw('discount_override_amount'),
                'tax_override_amount' => array_key_exists('tax_override_amount', $payload) ? $payload['tax_override_amount'] : DB::raw('tax_override_amount'),
                'rounding_override_amount' => array_key_exists('rounding_override_amount', $payload) ? $payload['rounding_override_amount'] : DB::raw('rounding_override_amount'),
                'note' => array_key_exists('note', $payload) ? trim((string) ($payload['note'] ?? '')) : DB::raw('note'),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            foreach ($payload['payments'] ?? [] as $payment) {
                if (empty($payment['id'])) continue;
                DB::table('finance_reconciliation_payments')
                    ->where('reconciliation_id', $id)
                    ->where('id', (string) $payment['id'])
                    ->update([
                        'actual_override_amount' => $payment['actual_override_amount'] ?? null,
                        'note' => trim((string) ($payment['note'] ?? '')),
                        'updated_at' => now(),
                    ]);
            }
            $this->recalculate($id, $userId);
        });
    }

    public function preview(string $id): array
    {
        $entry = $this->entry($id);
        if ($entry->status !== 'DRAFT') throw new InvalidArgumentException('Preview posting hanya untuk reconciliation DRAFT.');
        $this->assertSourceCurrent($entry);
        $this->assertResolved($entry);

        return [
            'reconciliation_id' => $id,
            'reconciliation_no' => (string) $entry->reconciliation_no,
            'scopes' => $this->postingScopes($entry),
        ];
    }

    public function post(string $id, string $journalDate, ?string $userId): array
    {
        $entry = $this->entry($id, true);
        if ($entry->status !== 'DRAFT') {
            $existing = DB::table('finance_reconciliation_postings')
                ->where('reconciliation_id', $id)->where('posting_version', (int) $entry->posting_version)
                ->get(['marking', 'journal_entry_id', 'journal_no'])
                ->map(fn ($row) => (array) $row)->all();
            if ($entry->status === 'POSTED') return ['version' => (int) $entry->posting_version, 'journals' => $existing, 'idempotent' => true];
            throw new InvalidArgumentException('Status reconciliation tidak dapat diposting.');
        }
        $this->assertSourceCurrent($entry);
        $this->assertResolved($entry);
        $scopes = $this->postingScopes($entry);
        if (empty($scopes)) throw new InvalidArgumentException('Tidak ada nilai yang dapat diposting pada reconciliation ini.');

        return DB::transaction(function () use ($entry, $scopes, $journalDate, $userId): array {
            $version = (int) $entry->posting_version + 1;
            $journals = [];
            foreach ($scopes as $scope) {
                $marking = $scope['marking'];
                $sourceKey = sprintf('RECONCILIATION:%s:V%d:%s', $entry->id, $version, $marking);
                $posted = $this->generalPosting->stageSystem([
                    'source_key' => $sourceKey,
                    'source_code' => 'RECONCILIATION',
                    'source_module' => 'RECONCILIATION',
                    'source_identity' => (string) $entry->id,
                    'reference_no' => (string) $entry->reconciliation_no,
                    'journal_date' => $journalDate,
                    'business_date' => (string) $entry->business_date,
                    'company_code' => (string) $entry->company_code,
                    'outlet_id' => (string) $entry->outlet_id,
                    'marking' => $marking,
                    'description' => 'Daily Reconciliation '.$entry->reconciliation_no.' '.$marking,
                    'metadata' => [
                        'producer_version' => 'F03',
                        'posting_version' => $version,
                        'payment_allocations' => $scope['payment_allocations'],
                        'totals' => $scope['totals'],
                    ],
                ], $scope['journal']['lines'], $userId, true);
                $journalId = (string) ($posted['journal_entry_id'] ?? '');
                $journalNo = (string) ($posted['journal_no'] ?? '');
                if ($journalId === '' || $journalNo === '') {
                    throw new InvalidArgumentException('General Posting Reconciliation gagal menghasilkan journal.');
                }
                DB::table('finance_reconciliation_postings')->insert([
                    'id' => (string) Str::ulid(),
                    'reconciliation_id' => (string) $entry->id,
                    'posting_version' => $version,
                    'marking' => $marking,
                    'journal_entry_id' => $journalId,
                    'journal_no' => $journalNo,
                    'reversal_journal_id' => null,
                    'reversal_journal_no' => null,
                    'posted_at' => now(),
                    'reversed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('finance_reconciliation_scope_summaries')
                    ->where('reconciliation_id', $entry->id)->where('marking', $marking)
                    ->update(['journal_entry_id' => $journalId, 'journal_no' => $journalNo, 'updated_at' => now()]);
                $journals[] = ['marking' => $marking, 'journal_entry_id' => $journalId, 'journal_no' => $journalNo];
            }

            DB::table('finance_reconciliations')->where('id', $entry->id)->update([
                'status' => 'POSTED',
                'posting_version' => $version,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            return ['version' => $version, 'journals' => $journals, 'idempotent' => false];
        });
    }

    public function reopen(string $id, string $reversalDate, string $reason, ?string $userId): array
    {
        $entry = $this->entry($id, true);
        if ($entry->status === 'DRAFT') return ['id' => $id, 'already_draft' => true, 'reversals' => []];
        if ($entry->status !== 'POSTED') throw new InvalidArgumentException('Hanya reconciliation POSTED yang dapat direopen.');

        return DB::transaction(function () use ($entry, $reversalDate, $reason, $userId): array {
            $postings = DB::table('finance_reconciliation_postings')
                ->where('reconciliation_id', $entry->id)
                ->where('posting_version', (int) $entry->posting_version)
                ->whereNull('reversal_journal_id')
                ->lockForUpdate()->get();
            $reversals = [];
            foreach ($postings as $posting) {
                $general = $this->generalPosting->generalPostingByJournal((string) $posting->journal_entry_id);
                if (! $general) {
                    $adopted = $this->generalPosting->adoptExistingJournal((string) $posting->journal_entry_id, $userId);
                    $generalId = (string) $adopted['general_posting_id'];
                } else {
                    $generalId = (string) $general->id;
                }
                $this->generalPosting->reopen($generalId, $reason, $userId);
                $link = DB::table('finance_general_posting_journals')
                    ->where('general_posting_id', $generalId)
                    ->where('journal_entry_id', (string) $posting->journal_entry_id)
                    ->first();
                $reversalId = (string) ($link->reversal_journal_id ?? '');
                $reversalNo = (string) ($link->reversal_journal_no ?? '');
                if ($reversalId === '') {
                    throw new InvalidArgumentException('Reversal General Posting Reconciliation tidak ditemukan.');
                }
                DB::table('finance_reconciliation_postings')->where('id', $posting->id)->update([
                    'reversal_journal_id' => $reversalId,
                    'reversal_journal_no' => $reversalNo,
                    'reversed_at' => now(),
                    'updated_at' => now(),
                ]);
                $reversals[] = ['marking' => (string) $posting->marking, 'reversal_journal_id' => $reversalId, 'reversal_journal_no' => $reversalNo];
            }
            DB::table('finance_reconciliation_scope_summaries')->where('reconciliation_id', $entry->id)->update([
                'journal_entry_id' => null, 'journal_no' => null, 'updated_at' => now(),
            ]);
            DB::table('finance_reconciliations')->where('id', $entry->id)->update([
                'status' => 'DRAFT', 'posted_at' => null, 'posted_by_user_id' => null,
                'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            return ['id' => (string) $entry->id, 'already_draft' => false, 'reversals' => $reversals];
        });
    }

    public function deleteDraft(string $id): string
    {
        return DB::transaction(function () use ($id): string {
            $entry = DB::table('finance_reconciliations')->where('id',$id)->lockForUpdate()->first();
            if (! $entry) return 'DELETED';
            if ($entry->status !== 'DRAFT') throw new InvalidArgumentException('Hanya reconciliation DRAFT yang dapat dihapus.');
            $hasHistory = DB::table('finance_reconciliation_postings')->where('reconciliation_id',$id)->exists();
            $hasSettlementReference = Schema::hasTable('finance_settlement_sources')
                && DB::table('finance_settlement_sources')->where('reconciliation_id',$id)->exists();
            if ($hasHistory || $hasSettlementReference) {
                // Audit/downstream Settlement identity cannot be physically deleted.
                // Archive the working document and keep payment/allocation IDs stable.
                DB::table('finance_reconciliations')->where('id',$id)->update([
                    'status'=>'CANCELLED','updated_at'=>now(),
                ]);
                return 'CANCELLED';
            }
            // Delete children explicitly first only when no downstream reference exists.
            // double cascade paths (allocation -> payment and allocation -> rec).
            DB::table('finance_reconciliation_payment_allocations')->where('reconciliation_id',$id)->delete();
            DB::table('finance_reconciliation_scope_summaries')->where('reconciliation_id',$id)->delete();
            DB::table('finance_reconciliation_payments')->where('reconciliation_id',$id)->delete();
            DB::table('finance_reconciliations')->where('id',$id)->delete();
            return 'DELETED';
        });
    }

    private function replaceSourceRows(string $id, array $source, array $existingOverrides, ?string $userId): void
    {
        // Post-F04: reconciliation rows have downstream RESTRICT FKs from finance_settlement_sources.
        // Never wholesale-delete payment/allocation rows. Upsert stable identities in place and
        // retire only rows that are not referenced downstream.
        $existingPayments = DB::table('finance_reconciliation_payments')
            ->where('reconciliation_id', $id)
            ->orderBy('sort_order')
            ->get();
        $usedPaymentIds = [];
        $sort = 0;

        foreach ($source['payment_methods'] as $row) {
            $key = mb_strtolower((string) $row['payment_method']);
            $override = array_key_exists($key, $existingOverrides) ? $existingOverrides[$key] : null;
            $paymentMethodId = $row['payment_method_id'] ?: null;

            $payment = $existingPayments->first(function ($existing) use ($paymentMethodId, $key): bool {
                if ($paymentMethodId && (string) ($existing->payment_method_id ?? '') === (string) $paymentMethodId) return true;
                return mb_strtolower((string) $existing->payment_method_name) === $key;
            });
            $paymentId = $payment ? (string) $payment->id : (string) Str::ulid();
            $usedPaymentIds[] = $paymentId;

            $payload = [
                'payment_method_id' => $paymentMethodId,
                'payment_method_name' => $row['payment_method'],
                'pos_amount' => $row['pos_amount'],
                'overhandle_amount' => $row['overhandle_amount'],
                'actual_override_amount' => $override,
                'effective_actual_amount' => 0,
                'variance_amount' => 0,
                'requires_actual' => (bool) $row['requires_actual'],
                'sort_order' => ++$sort,
                'updated_at' => now(),
            ];
            if ($payment) {
                DB::table('finance_reconciliation_payments')->where('id', $paymentId)->update($payload);
            } else {
                DB::table('finance_reconciliation_payments')->insert($payload + [
                    'id' => $paymentId, 'reconciliation_id' => $id, 'note' => null, 'created_at' => now(),
                ]);
            }

            foreach (['MARKING' => 'marking_pos_amount', 'UNMARKING' => 'unmarking_pos_amount'] as $marking => $field) {
                $allocation = DB::table('finance_reconciliation_payment_allocations')
                    ->where('reconciliation_id', $id)
                    ->where('reconciliation_payment_id', $paymentId)
                    ->where('marking', $marking)
                    ->first();
                $allocationPayload = [
                    'pos_amount' => $row[$field],
                    'effective_actual_amount' => 0,
                    'variance_amount' => 0,
                    'updated_at' => now(),
                ];
                if ($allocation) {
                    DB::table('finance_reconciliation_payment_allocations')->where('id', $allocation->id)->update($allocationPayload);
                } else {
                    DB::table('finance_reconciliation_payment_allocations')->insert($allocationPayload + [
                        'id' => (string) Str::ulid(),
                        'reconciliation_id' => $id,
                        'reconciliation_payment_id' => $paymentId,
                        'marking' => $marking,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // A payment method can disappear from a refreshed POS source. Preserve referenced
        // rows as zero-value audit anchors for Settlement; safely delete only unreferenced rows.
        foreach ($existingPayments as $payment) {
            if (in_array((string) $payment->id, $usedPaymentIds, true)) continue;
            $allocationIds = DB::table('finance_reconciliation_payment_allocations')
                ->where('reconciliation_id', $id)
                ->where('reconciliation_payment_id', $payment->id)
                ->pluck('id');
            $referenced = Schema::hasTable('finance_settlement_sources') && DB::table('finance_settlement_sources')
                ->where(function ($q) use ($payment, $allocationIds): void {
                    $q->where('reconciliation_payment_id', $payment->id);
                    if ($allocationIds->isNotEmpty()) $q->orWhereIn('reconciliation_allocation_id', $allocationIds);
                })->exists();
            if ($referenced) {
                DB::table('finance_reconciliation_payments')->where('id', $payment->id)->update([
                    'pos_amount'=>0,'overhandle_amount'=>0,'actual_override_amount'=>0,'effective_actual_amount'=>0,
                    'variance_amount'=>0,'requires_actual'=>false,'note'=>'F05 archived source row; retained because Settlement references this identity.',
                    'sort_order'=>++$sort,'updated_at'=>now(),
                ]);
                if ($allocationIds->isNotEmpty()) DB::table('finance_reconciliation_payment_allocations')->whereIn('id',$allocationIds)->update([
                    'pos_amount'=>0,'effective_actual_amount'=>0,'variance_amount'=>0,'updated_at'=>now(),
                ]);
            } else {
                if ($allocationIds->isNotEmpty()) DB::table('finance_reconciliation_payment_allocations')->whereIn('id',$allocationIds)->delete();
                DB::table('finance_reconciliation_payments')->where('id',$payment->id)->delete();
            }
        }

        foreach ($source['scopes'] as $scope) {
            $summary = DB::table('finance_reconciliation_scope_summaries')
                ->where('reconciliation_id', $id)->where('marking', $scope['marking'])->first();
            $payload = [
                'transaction_count' => $scope['transaction_count'], 'pos_total' => $scope['pos_total'],
                'effective_actual_total' => 0, 'discount_total' => $scope['discount_total'], 'tax_total' => $scope['tax_total'],
                'rounding_total' => $scope['rounding_total'], 'revenue_total' => 0, 'variance_shortage' => 0, 'variance_overage' => 0,
                'journal_entry_id' => null, 'journal_no' => null, 'updated_at' => now(),
            ];
            if ($summary) DB::table('finance_reconciliation_scope_summaries')->where('id',$summary->id)->update($payload);
            else DB::table('finance_reconciliation_scope_summaries')->insert($payload + [
                'id'=>(string)Str::ulid(),'reconciliation_id'=>$id,'marking'=>$scope['marking'],'created_at'=>now(),
            ]);
        }
    }

    private function recalculate(string $id, ?string $userId): void
    {
        $entry = DB::table('finance_reconciliations')->where('id', $id)->first();
        $snapshot = json_decode((string) $entry->source_snapshot, true) ?: [];
        $paymentSource = collect($snapshot['payment_methods'] ?? [])->keyBy(fn ($row) => mb_strtolower((string) $row['payment_method']));
        $payments = DB::table('finance_reconciliation_payments')->where('reconciliation_id', $id)->orderBy('sort_order')->get();
        $varianceEnabled = (bool) ($snapshot['variance_enabled'] ?? true);
        $hasOverhandle = (bool) data_get($snapshot, 'overhandle.has_closing', (bool) ($entry->has_overhandle ?? false));
        $unresolved = 0;
        $actualTotal = 0.0;

        foreach ($payments as $payment) {
            $source = $paymentSource->get(mb_strtolower((string) $payment->payment_method_name), []);
            $rawOverhandle = $hasOverhandle ? (float) $payment->overhandle_amount : 0.0;
            $same = abs((float) $payment->pos_amount - $rawOverhandle) <= 0.005;
            $override = $payment->actual_override_amount === null ? null : (float) $payment->actual_override_amount;
            $effective = $override;
            if ($override === null && ! $varianceEnabled) $effective = (float) $payment->pos_amount;
            elseif ($override === null && $hasOverhandle && $same) $effective = (float) $payment->pos_amount;
            if ($effective === null) $unresolved++;
            $effectiveNumber = round((float) ($effective ?? 0), 2);
            $actualTotal += $effectiveNumber;

            DB::table('finance_reconciliation_payments')->where('id', $payment->id)->update([
                'overhandle_amount' => round($rawOverhandle, 2),
                'effective_actual_amount' => $effectiveNumber,
                'variance_amount' => round($effectiveNumber - (float) $payment->pos_amount, 2),
                'requires_actual' => $varianceEnabled && (! $hasOverhandle || ! $same) && $override === null,
                'updated_at' => now(),
            ]);

            $markPos = (float) ($source['marking_pos_amount'] ?? 0);
            $unmarkPos = (float) ($source['unmarking_pos_amount'] ?? 0);
            [$markActual, $unmarkActual] = $this->allocate($effectiveNumber, $markPos, $unmarkPos);
            foreach (['MARKING' => [$markPos, $markActual], 'UNMARKING' => [$unmarkPos, $unmarkActual]] as $marking => [$pos, $actual]) {
                DB::table('finance_reconciliation_payment_allocations')
                    ->where('reconciliation_payment_id', $payment->id)->where('marking', $marking)
                    ->update([
                        'pos_amount' => $pos,
                        'effective_actual_amount' => $actual,
                        'variance_amount' => round($actual - $pos, 2),
                        'updated_at' => now(),
                    ]);
            }
        }

        $effectiveDiscount = $entry->discount_override_amount === null ? (float) $entry->pos_discount_total : (float) $entry->discount_override_amount;
        $effectiveTax = $entry->tax_override_amount === null ? (float) $entry->pos_tax_total : (float) $entry->tax_override_amount;
        $effectiveRounding = $entry->rounding_override_amount === null ? (float) $entry->pos_rounding_total : (float) $entry->rounding_override_amount;
        $scopeSource = collect($snapshot['scopes'] ?? [])->keyBy('marking');
        $markedSource = $scopeSource->get('MARKING', []);
        $unmarkedSource = $scopeSource->get('UNMARKING', []);
        [$markedDiscount, $unmarkedDiscount] = $this->allocate($effectiveDiscount, (float) ($markedSource['discount_total'] ?? 0), (float) ($unmarkedSource['discount_total'] ?? 0));
        [$markedTax, $unmarkedTax] = $this->allocate($effectiveTax, (float) ($markedSource['tax_total'] ?? 0), (float) ($unmarkedSource['tax_total'] ?? 0));
        [$markedRounding, $unmarkedRounding] = $this->allocateSigned($effectiveRounding, (float) ($markedSource['rounding_total'] ?? 0), (float) ($unmarkedSource['rounding_total'] ?? 0));

        foreach (['MARKING' => [$markedDiscount, $markedTax, $markedRounding], 'UNMARKING' => [$unmarkedDiscount, $unmarkedTax, $unmarkedRounding]] as $marking => [$discount, $tax, $rounding]) {
            $alloc = DB::table('finance_reconciliation_payment_allocations')->where('reconciliation_id', $id)->where('marking', $marking)->get();
            $pos = round((float) $alloc->sum('pos_amount'), 2);
            $actual = round((float) $alloc->sum('effective_actual_amount'), 2);
            $variance = round($actual - $pos, 2);
            DB::table('finance_reconciliation_scope_summaries')->where('reconciliation_id', $id)->where('marking', $marking)->update([
                'pos_total' => $pos,
                'effective_actual_total' => $actual,
                'discount_total' => round($discount, 2),
                'tax_total' => round($tax, 2),
                'rounding_total' => round($rounding, 2),
                'revenue_total' => round($pos + $discount - $tax - $rounding, 2),
                'variance_shortage' => max(0, -$variance),
                'variance_overage' => max(0, $variance),
                'updated_at' => now(),
            ]);
        }

        DB::table('finance_reconciliations')->where('id', $id)->update([
            'overhandle_total' => $hasOverhandle ? DB::table('finance_reconciliation_payments')->where('reconciliation_id', $id)->sum('overhandle_amount') : 0,
            'effective_actual_total' => round($actualTotal, 2),
            'effective_discount_total' => round($effectiveDiscount, 2),
            'effective_tax_total' => round($effectiveTax, 2),
            'effective_rounding_total' => round($effectiveRounding, 2),
            'unresolved_payment_count' => $unresolved,
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ]);
    }

    private function postingScopes(object $entry): array
    {
        $summaries = DB::table('finance_reconciliation_scope_summaries')->where('reconciliation_id', $entry->id)->orderBy('marking')->get();
        $result = [];
        foreach ($summaries as $summary) {
            $material = abs((float) $summary->pos_total) + abs((float) $summary->effective_actual_total) + abs((float) $summary->discount_total) + abs((float) $summary->tax_total) + abs((float) $summary->rounding_total);
            if ($material <= 0.005) continue;
            $rounding = (float) $summary->rounding_total;
            $context = [
                'company_code' => (string) $entry->company_code,
                'outlet_id' => (string) $entry->outlet_id,
                'marking' => (string) $summary->marking,
                'business_date' => (string) $entry->business_date,
                'reference_no' => (string) $entry->reconciliation_no,
                'description' => 'Reconciliation '.$entry->reconciliation_no.' '.$summary->marking,
                'actual_total' => (float) $summary->effective_actual_total,
                'pos_total' => (float) $summary->pos_total,
                'discount_total' => (float) $summary->discount_total,
                'tax_total' => (float) $summary->tax_total,
                'rounding_positive' => max(0, $rounding),
                'rounding_negative' => max(0, -$rounding),
                'variance_shortage' => (float) $summary->variance_shortage,
                'variance_overage' => (float) $summary->variance_overage,
                'revenue_total' => (float) $summary->revenue_total,
            ];
            $journal = $this->templateEngine->preview(null, 'RECONCILIATION', $context);
            $allocations = DB::table('finance_reconciliation_payment_allocations as a')
                ->join('finance_reconciliation_payments as p', 'p.id', '=', 'a.reconciliation_payment_id')
                ->where('a.reconciliation_id', $entry->id)->where('a.marking', $summary->marking)
                ->where(function ($q): void { $q->where('a.pos_amount', '<>', 0)->orWhere('a.effective_actual_amount', '<>', 0); })
                ->orderBy('p.sort_order')
                ->get(['p.payment_method_id','p.payment_method_name','a.pos_amount','a.effective_actual_amount','a.variance_amount'])
                ->map(fn ($row) => [
                    'payment_method_id' => $row->payment_method_id ? (string) $row->payment_method_id : null,
                    'payment_method' => (string) $row->payment_method_name,
                    'pos_amount' => (float) $row->pos_amount,
                    'actual_amount' => (float) $row->effective_actual_amount,
                    'variance_amount' => (float) $row->variance_amount,
                ])->values()->all();
            $journal = $this->expandClearingByPaymentMethod($journal, $allocations, (string) $summary->marking);
            $result[] = [
                'marking' => (string) $summary->marking,
                'totals' => $context,
                'payment_allocations' => $allocations,
                'journal' => $journal,
            ];
        }
        return $result;
    }

    private function expandClearingByPaymentMethod(array $journal, array $allocations, string $marking): array
    {
        $actualAllocations = array_values(array_filter($allocations, fn (array $row) => abs((float) ($row['actual_amount'] ?? 0)) > 0.005));
        if (count($actualAllocations) <= 1) return $journal;

        $templateId = (string) ($journal['template']['id'] ?? '');
        $templateRows = DB::table('finance_posting_template_lines')->where('template_id', $templateId)->orderBy('sort_order')->get();
        $clearingTemplate = $templateRows->first(function ($row): bool {
            $meta = json_decode((string) ($row->meta ?? ''), true) ?: [];
            return ($meta['role'] ?? null) === 'clearing';
        }) ?: $templateRows->first(fn ($row) => strtoupper((string) $row->side) === 'DEBIT' && str_contains((string) $row->amount_formula, 'actual_total'));

        if (! $clearingTemplate) {
            throw new InvalidArgumentException('Template RECONCILIATION harus memiliki baris clearing DEBIT dengan formula {{actual_total}} agar Dana Belum Disetor dapat dipisah per payment method.');
        }

        $clearingIndex = null;
        foreach ($journal['lines'] as $index => $line) {
            if ((string) ($line['account_id'] ?? '') === (string) $clearingTemplate->account_id && (float) ($line['debit'] ?? 0) > 0) {
                $clearingIndex = $index;
                break;
            }
        }
        if ($clearingIndex === null) return $journal;

        $base = $journal['lines'][$clearingIndex];
        $replacement = [];
        foreach ($actualAllocations as $allocation) {
            $line = $base;
            $line['description'] = sprintf('Dana Belum Disetor - %s - %s', (string) $allocation['payment_method'], $marking);
            $line['debit'] = round((float) $allocation['actual_amount'], 2);
            $line['credit'] = 0.0;
            $replacement[] = $line;
        }
        array_splice($journal['lines'], $clearingIndex, 1, $replacement);
        $journal['total_debit'] = round(array_sum(array_column($journal['lines'], 'debit')), 2);
        $journal['total_credit'] = round(array_sum(array_column($journal['lines'], 'credit')), 2);
        $journal['balanced'] = abs($journal['total_debit'] - $journal['total_credit']) <= 0.005;
        if (! $journal['balanced']) throw new InvalidArgumentException('Split Dana Belum Disetor per payment method membuat jurnal tidak balance.');
        return $journal;
    }

    private function assertSourceCurrent(object $entry): void
    {
        $current = $this->sourceService->build((string) $entry->outlet_id, (string) $entry->business_date);
        if (! hash_equals((string) $entry->source_fingerprint, (string) $current['source_fingerprint'])) {
            throw new InvalidArgumentException('Source Cashier/Overhandle berubah sejak draft disimpan. Klik Refresh Source sebelum posting.');
        }
    }

    private function assertResolved(object $entry): void
    {
        if ((int) $entry->unresolved_payment_count > 0) {
            throw new InvalidArgumentException('Masih ada payment method dengan selisih POS vs Overhandle yang belum memiliki Actual.');
        }
        if ((float) $entry->effective_actual_total < 0 || (float) $entry->effective_discount_total < 0 || (float) $entry->effective_tax_total < 0) {
            throw new InvalidArgumentException('Actual, discount, dan tax tidak boleh negatif.');
        }
    }

    private function entry(string $id, bool $lock = false): object
    {
        $query = DB::table('finance_reconciliations')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $entry = $query->first();
        if (! $entry) throw new InvalidArgumentException('Reconciliation tidak ditemukan.');
        return $entry;
    }

    private function allocate(float $effective, float $markedBase, float $unmarkedBase): array
    {
        $effective = round(max(0, $effective), 2);
        $markedBase = max(0, $markedBase); $unmarkedBase = max(0, $unmarkedBase);
        $base = $markedBase + $unmarkedBase;
        if ($base <= 0.005) return [$effective, 0.0];
        $marked = round($effective * ($markedBase / $base), 2);
        return [$marked, round($effective - $marked, 2)];
    }

    private function allocateSigned(float $effective, float $markedBase, float $unmarkedBase): array
    {
        $sign = $effective < 0 ? -1 : 1;
        [$a, $b] = $this->allocate(abs($effective), abs($markedBase), abs($unmarkedBase));
        return [round($a * $sign, 2), round($b * $sign, 2)];
    }

    private function nextNumber(string $date): string
    {
        $prefix = 'REC-'.str_replace('-', '', $date).'-';
        $last = DB::table('finance_reconciliations')->where('reconciliation_no', 'like', $prefix.'%')->lockForUpdate()->max('reconciliation_no');
        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;
        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
