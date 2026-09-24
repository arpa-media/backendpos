<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceSettlementService
{
    public function __construct(private readonly FinanceGeneralPostingService $generalPosting)
    {
    }

    public function syncSources(?array $outletIds = null, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $query = DB::table('finance_reconciliations as r')
            ->join('finance_reconciliation_payments as p', 'p.reconciliation_id', '=', 'r.id')
            ->join('finance_reconciliation_payment_allocations as a', function ($join): void {
                $join->on('a.reconciliation_id', '=', 'r.id')->on('a.reconciliation_payment_id', '=', 'p.id');
            })
            ->join('finance_reconciliation_postings as rp', function ($join): void {
                $join->on('rp.reconciliation_id', '=', 'r.id')
                    ->on('rp.posting_version', '=', 'r.posting_version')
                    ->on('rp.marking', '=', 'a.marking');
            })
            ->join('finance_journal_entries as j', 'j.id', '=', 'rp.journal_entry_id')
            ->where('r.status', 'POSTED')
            ->where('j.status', 'POSTED')
            ->whereNull('rp.reversal_journal_id')
            ->where('a.effective_actual_amount', '>', 0.005)
            ->when($outletIds !== null, fn ($q) => empty($outletIds) ? $q->whereRaw('1=0') : $q->whereIn('r.outlet_id', $outletIds))
            ->when($dateFrom, fn ($q, $v) => $q->where('r.business_date', '>=', $v))
            ->when($dateTo, fn ($q, $v) => $q->where('r.business_date', '<=', $v))
            ->select([
                'r.id as reconciliation_id', 'r.reconciliation_no', 'r.business_date', 'r.company_code', 'r.outlet_id', 'r.posting_version',
                'p.id as reconciliation_payment_id', 'p.payment_method_id', 'p.payment_method_name',
                'a.id as reconciliation_allocation_id', 'a.marking', 'a.effective_actual_amount',
                'rp.id as reconciliation_posting_id', 'rp.journal_entry_id', 'rp.journal_no',
            ])
            ->orderBy('r.business_date')->orderBy('r.id')->orderBy('a.marking')->lazy(250);

        $count = 0;
        DB::transaction(function () use ($query, &$count): void {
            foreach ($query as $row) {
                $sourceKey = sprintf('RECON:%s:V%d:%s:%s', $row->reconciliation_id, (int) $row->posting_version, $row->marking, $row->reconciliation_payment_id);
                $clearing = $this->resolveClearingLine((string) $row->journal_entry_id, (string) $row->payment_method_name, (string) $row->marking, (float) $row->effective_actual_amount);
                $mapping = $this->resolveMapping((string) $row->company_code, (string) $row->outlet_id, $row->payment_method_id ? (string) $row->payment_method_id : null, (string) $row->payment_method_name, (string) $row->marking);
                $expected = CarbonImmutable::parse((string) $row->business_date)->addDays((int) ($mapping->settlement_days ?? 1))->toDateString();
                $existing = DB::table('finance_settlement_sources')->where('source_key', $sourceKey)->first();
                $payload = [
                    'reconciliation_id' => (string) $row->reconciliation_id,
                    'reconciliation_no' => (string) $row->reconciliation_no,
                    'reconciliation_posting_id' => (string) $row->reconciliation_posting_id,
                    'reconciliation_payment_id' => (string) $row->reconciliation_payment_id,
                    'reconciliation_allocation_id' => (string) $row->reconciliation_allocation_id,
                    'posting_version' => (int) $row->posting_version,
                    'company_code' => (string) $row->company_code,
                    'outlet_id' => (string) $row->outlet_id,
                    'business_date' => (string) $row->business_date,
                    'expected_settlement_date' => $expected,
                    'marking' => (string) $row->marking,
                    'payment_method_id' => $row->payment_method_id ? (string) $row->payment_method_id : null,
                    'payment_method_name' => (string) $row->payment_method_name,
                    'source_journal_entry_id' => (string) $row->journal_entry_id,
                    'source_journal_no' => (string) $row->journal_no,
                    'clearing_account_id' => (string) $clearing->account_id,
                    'clearing_account_code' => (string) $clearing->account_code,
                    'clearing_account_name' => (string) $clearing->account_name,
                    'original_amount' => round((float) $row->effective_actual_amount, 2),
                    'mapping_id' => $mapping?->id ? (string) $mapping->id : null,
                    'updated_at' => now(),
                ];
                if ($existing) {
                    DB::table('finance_settlement_sources')->where('id', $existing->id)->update($payload);
                    $sourceId = (string) $existing->id;
                } else {
                    $sourceId = (string) Str::ulid();
                    DB::table('finance_settlement_sources')->insert($payload + [
                        'id' => $sourceId,
                        'source_key' => $sourceKey,
                        'settled_amount' => 0,
                        'status' => $mapping ? 'OPEN' : 'MAPPING_REQUIRED',
                        'created_at' => now(),
                    ]);
                }
                $this->recalculateSource($sourceId);
                $count++;
            }
        });

        return $count;
    }

    public function saveMapping(array $data, ?string $id, ?string $userId): string
    {
        $company = strtoupper(trim((string) ($data['company_code'] ?? '')));
        if (! in_array($company, ['BKJB', 'MDMF'], true)) throw new InvalidArgumentException('PT mapping wajib BKJB atau MDMF.');
        $marking = strtoupper(trim((string) ($data['marking'] ?? 'ALL')));
        if (! in_array($marking, ['ALL', 'MARKING', 'UNMARKING'], true)) throw new InvalidArgumentException('Marking mapping tidak valid.');
        $outletId = $this->nullable($data['outlet_id'] ?? null);
        if ($outletId) {
            $mappedCompany = DB::table('finance_outlet_company_mappings')->where('outlet_id', $outletId)->where('is_active', true)->value('company_code');
            if (! $mappedCompany) throw new InvalidArgumentException('Outlet belum dipetakan ke PT pada Chart of Account → Mapping PT Outlet.');
            if (strtoupper((string) $mappedCompany) !== $company) throw new InvalidArgumentException('PT mapping Settlement tidak sesuai dengan PT outlet.');
        }
        $paymentMethodId = $this->nullable($data['payment_method_id'] ?? null);
        $paymentName = trim((string) ($data['payment_method_name'] ?? ''));
        if (! $paymentMethodId && $paymentName === '') throw new InvalidArgumentException('Payment method wajib dipilih/diisi.');
        if ($paymentMethodId) {
            $pm = DB::table('payment_methods')->where('id', $paymentMethodId)->first(['name']);
            if (! $pm) throw new InvalidArgumentException('Payment method tidak ditemukan.');
            $paymentName = (string) $pm->name;
        }

        $this->assertAccount((string) $data['bank_account_id'], ['ASSET'], 'Rekening Bank/Kas');
        if (! empty($data['mdr_expense_account_id'])) $this->assertExpenseAccount((string) $data['mdr_expense_account_id'], 'COA MDR');
        if (! empty($data['admin_fee_expense_account_id'])) $this->assertExpenseAccount((string) $data['admin_fee_expense_account_id'], 'COA Admin Fee');

        $mappingKey = implode('|', [$company, $outletId ?: '*', $paymentMethodId ?: mb_strtolower($paymentName), $marking]);
        $duplicate = DB::table('finance_settlement_mappings')->where('mapping_key', $mappingKey)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($duplicate) throw new InvalidArgumentException('Mapping Settlement dengan scope yang sama sudah tersedia.');

        return DB::transaction(function () use ($data, $id, $userId, $company, $marking, $outletId, $paymentMethodId, $paymentName, $mappingKey): string {
            $mappingId = $id ?: (string) Str::ulid();
            $payload = [
                'mapping_key' => $mappingKey,
                'company_code' => $company,
                'outlet_id' => $outletId,
                'payment_method_id' => $paymentMethodId,
                'payment_method_name' => $paymentName,
                'marking' => $marking,
                'settlement_days' => max(0, min(30, (int) ($data['settlement_days'] ?? 1))),
                'bank_account_id' => (string) $data['bank_account_id'],
                'mdr_expense_account_id' => $this->nullable($data['mdr_expense_account_id'] ?? null),
                'admin_fee_expense_account_id' => $this->nullable($data['admin_fee_expense_account_id'] ?? null),
                'mdr_rate' => round(max(0, (float) ($data['mdr_rate'] ?? 0)), 6),
                'mdr_fixed' => round(max(0, (float) ($data['mdr_fixed'] ?? 0)), 2),
                'admin_fee_rate' => round(max(0, (float) ($data['admin_fee_rate'] ?? 0)), 6),
                'admin_fee_fixed' => round(max(0, (float) ($data['admin_fee_fixed'] ?? 0)), 2),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notes' => $this->nullable($data['notes'] ?? null),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ];
            if ($id) {
                if (! DB::table('finance_settlement_mappings')->where('id', $id)->exists()) throw new InvalidArgumentException('Mapping Settlement tidak ditemukan.');
                DB::table('finance_settlement_mappings')->where('id', $id)->update($payload);
            } else {
                DB::table('finance_settlement_mappings')->insert($payload + ['id' => $mappingId, 'created_by_user_id' => $userId, 'created_at' => now()]);
            }
            return $mappingId;
        });
    }

    public function deleteMapping(string $id): void
    {
        if (DB::table('finance_settlements')->where('mapping_id', $id)->exists()) {
            DB::table('finance_settlement_mappings')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            return;
        }
        DB::table('finance_settlement_mappings')->where('id', $id)->delete();
    }

    public function createDraft(string $sourceId, string $settlementDate, ?float $clearingAmount, ?string $userId): string
    {
        return DB::transaction(function () use ($sourceId, $settlementDate, $clearingAmount, $userId): string {
            $source = DB::table('finance_settlement_sources')->where('id', $sourceId)->lockForUpdate()->first();
            if (! $source) throw new InvalidArgumentException('Source Settlement tidak ditemukan.');
            $this->assertSourceActive($source);
            $mapping = $this->resolveMapping((string) $source->company_code, (string) $source->outlet_id, $source->payment_method_id ? (string) $source->payment_method_id : null, (string) $source->payment_method_name, (string) $source->marking);
            if (! $mapping) throw new InvalidArgumentException('Mapping Settlement belum tersedia untuk payment method/scope ini.');

            $expected = CarbonImmutable::parse((string) $source->business_date)->addDays((int) $mapping->settlement_days)->toDateString();
            if ($settlementDate < $expected) throw new InvalidArgumentException("Settlement paling cepat {$expected} (H+{$mapping->settlement_days}).");
            $available = $this->availableAmount((string) $source->id);
            $gross = $clearingAmount === null ? $available : round($clearingAmount, 2);
            if ($gross <= 0.005) throw new InvalidArgumentException('Tidak ada outstanding yang dapat disettle.');
            if ($gross - $available > 0.005) throw new InvalidArgumentException('Nominal settlement melebihi outstanding yang tersedia.');

            $mdr = round(($gross * (float) $mapping->mdr_rate / 100) + (float) $mapping->mdr_fixed, 2);
            $admin = round(($gross * (float) $mapping->admin_fee_rate / 100) + (float) $mapping->admin_fee_fixed, 2);
            if ($mdr + $admin - $gross > 0.005) throw new InvalidArgumentException('Default MDR + Admin Fee melebihi nilai settlement. Perbaiki mapping.');
            $bank = round($gross - $mdr - $admin, 2);

            $id = (string) Str::ulid();
            DB::table('finance_settlements')->insert([
                'id' => $id,
                'settlement_no' => $this->nextNumber($settlementDate),
                'settlement_source_id' => (string) $source->id,
                'mapping_id' => (string) $mapping->id,
                'settlement_date' => $settlementDate,
                'business_date' => (string) $source->business_date,
                'company_code' => (string) $source->company_code,
                'outlet_id' => (string) $source->outlet_id,
                'marking' => (string) $source->marking,
                'payment_method_id' => $source->payment_method_id ? (string) $source->payment_method_id : null,
                'payment_method_name' => (string) $source->payment_method_name,
                'clearing_account_id' => (string) $source->clearing_account_id,
                'bank_account_id' => (string) $mapping->bank_account_id,
                'mdr_expense_account_id' => $mapping->mdr_expense_account_id ? (string) $mapping->mdr_expense_account_id : null,
                'admin_fee_expense_account_id' => $mapping->admin_fee_expense_account_id ? (string) $mapping->admin_fee_expense_account_id : null,
                'clearing_amount' => $gross,
                'bank_received_amount' => $bank,
                'mdr_amount' => $mdr,
                'admin_fee_amount' => $admin,
                'status' => 'DRAFT',
                'posting_version' => 0,
                'journal_entry_id' => null, 'journal_no' => null,
                'reversal_journal_id' => null, 'reversal_journal_no' => null,
                'note' => null,
                'posted_at' => null, 'posted_by_user_id' => null,
                'reversed_at' => null, 'reversed_by_user_id' => null,
                'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->recalculateSource((string) $source->id);
            return $id;
        });
    }

    public function updateDraft(string $id, array $data, ?string $userId): void
    {
        DB::transaction(function () use ($id, $data, $userId): void {
            $settlement = DB::table('finance_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $settlement) throw new InvalidArgumentException('Settlement tidak ditemukan.');
            if ($settlement->status !== 'DRAFT') throw new InvalidArgumentException('Hanya Settlement DRAFT yang dapat diedit.');
            $source = DB::table('finance_settlement_sources')->where('id', $settlement->settlement_source_id)->lockForUpdate()->first();
            if (! $source) throw new InvalidArgumentException('Source Settlement tidak ditemukan.');
            $this->assertSourceActive($source);

            $date = (string) ($data['settlement_date'] ?? $settlement->settlement_date);
            if ($date < (string) $source->expected_settlement_date) throw new InvalidArgumentException('Tanggal settlement lebih awal dari expected H+ settlement.');
            $gross = round((float) ($data['clearing_amount'] ?? $settlement->clearing_amount), 2);
            $bank = round((float) ($data['bank_received_amount'] ?? $settlement->bank_received_amount), 2);
            $mdr = round((float) ($data['mdr_amount'] ?? $settlement->mdr_amount), 2);
            $admin = round((float) ($data['admin_fee_amount'] ?? $settlement->admin_fee_amount), 2);
            $this->assertAmounts($gross, $bank, $mdr, $admin);
            $available = $this->availableAmount((string) $source->id, $id);
            if ($gross - $available > 0.005) throw new InvalidArgumentException('Nominal settlement melebihi outstanding yang tersedia.');
            if ($mdr > 0.005 && ! $settlement->mdr_expense_account_id) throw new InvalidArgumentException('COA MDR belum dimapping.');
            if ($admin > 0.005 && ! $settlement->admin_fee_expense_account_id) throw new InvalidArgumentException('COA Admin Fee belum dimapping.');

            DB::table('finance_settlements')->where('id', $id)->update([
                'settlement_date' => $date,
                'clearing_amount' => $gross,
                'bank_received_amount' => $bank,
                'mdr_amount' => $mdr,
                'admin_fee_amount' => $admin,
                'note' => $this->nullable($data['note'] ?? $settlement->note),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            $this->recalculateSource((string) $source->id);
        });
    }

    public function preview(string $id): array
    {
        $settlement = DB::table('finance_settlements')->where('id', $id)->first();
        if (! $settlement) throw new InvalidArgumentException('Settlement tidak ditemukan.');
        if ($settlement->status !== 'DRAFT') throw new InvalidArgumentException('Preview hanya untuk Settlement DRAFT.');
        $source = DB::table('finance_settlement_sources')->where('id', $settlement->settlement_source_id)->first();
        if (! $source) throw new InvalidArgumentException('Source Settlement tidak ditemukan.');
        $this->assertSourceActive($source);
        return $this->journalPreview($settlement, $source);
    }

    public function post(string $id, ?string $userId): array
    {
        return DB::transaction(function () use ($id, $userId): array {
            $settlement = DB::table('finance_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $settlement) throw new InvalidArgumentException('Settlement tidak ditemukan.');
            if ($settlement->status === 'POSTED') return ['id' => $id, 'journal_entry_id' => $settlement->journal_entry_id, 'journal_no' => $settlement->journal_no, 'idempotent' => true];
            if ($settlement->status !== 'DRAFT') throw new InvalidArgumentException('Status Settlement tidak dapat diposting.');
            $source = DB::table('finance_settlement_sources')->where('id', $settlement->settlement_source_id)->lockForUpdate()->first();
            if (! $source) throw new InvalidArgumentException('Source Settlement tidak ditemukan.');
            $this->assertSourceActive($source);
            $this->assertAmounts((float) $settlement->clearing_amount, (float) $settlement->bank_received_amount, (float) $settlement->mdr_amount, (float) $settlement->admin_fee_amount);
            $availableForCurrent = $this->availableAmount((string) $source->id, $id);
            if ((float) $settlement->clearing_amount - $availableForCurrent > 0.005) throw new InvalidArgumentException('Settlement melebihi outstanding source karena ada draft/posting lain yang lebih dahulu menggunakan nilai tersebut.');

            $preview = $this->journalPreview($settlement, $source);
            $version = (int) $settlement->posting_version + 1;
            $posted = $this->generalPosting->stageSystem([
                'source_key' => sprintf('SETTLEMENT:%s:V%d', $settlement->id, $version),
                'source_code' => 'SETTLEMENT',
                'source_module' => 'SETTLEMENT',
                'source_identity' => (string) $settlement->id,
                'reference_no' => (string) $settlement->settlement_no,
                'journal_date' => (string) $settlement->settlement_date,
                'business_date' => (string) $settlement->business_date,
                'company_code' => (string) $settlement->company_code,
                'outlet_id' => (string) $settlement->outlet_id,
                'marking' => (string) $settlement->marking,
                'mdr' => (float) $settlement->mdr_amount,
                'admin_fee' => (float) $settlement->admin_fee_amount,
                'description' => sprintf('Settlement %s · %s · %s', $settlement->settlement_no, $settlement->payment_method_name, $settlement->marking),
                'metadata' => [
                    'producer_version' => 'F03',
                    'settlement_source_id' => (string) $source->id,
                    'reconciliation_id' => (string) $source->reconciliation_id,
                    'reconciliation_no' => (string) $source->reconciliation_no,
                    'source_journal_entry_id' => (string) $source->source_journal_entry_id,
                    'payment_method_id' => $settlement->payment_method_id,
                    'payment_method_name' => $settlement->payment_method_name,
                    'gross_clearing' => (float) $settlement->clearing_amount,
                    'bank_received' => (float) $settlement->bank_received_amount,
                    'mdr' => (float) $settlement->mdr_amount,
                    'admin_fee' => (float) $settlement->admin_fee_amount,
                    'posting_version' => $version,
                ],
            ], $preview['lines'], $userId, true);
            $journalId = (string) ($posted['journal_entry_id'] ?? '');
            $journalNo = (string) ($posted['journal_no'] ?? '');
            if ($journalId === '' || $journalNo === '') {
                throw new InvalidArgumentException('General Posting Settlement gagal menghasilkan journal.');
            }
            DB::table('finance_settlements')->where('id', $id)->update([
                'status' => 'POSTED', 'posting_version' => $version,
                'journal_entry_id' => $journalId, 'journal_no' => $journalNo,
                'reversal_journal_id' => null, 'reversal_journal_no' => null,
                'posted_at' => now(), 'posted_by_user_id' => $userId,
                'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            $this->recalculateSource((string) $source->id);
            return ['id' => $id, 'journal_entry_id' => $journalId, 'journal_no' => $journalNo, 'idempotent' => false];
        });
    }

    public function reopen(string $id, string $reversalDate, string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($id, $reversalDate, $reason, $userId): array {
            $settlement = DB::table('finance_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $settlement) throw new InvalidArgumentException('Settlement tidak ditemukan.');
            if ($settlement->status === 'DRAFT') return ['id' => $id, 'already_draft' => true];
            if ($settlement->status !== 'POSTED') throw new InvalidArgumentException('Hanya Settlement POSTED yang dapat direopen.');
            $general = $this->generalPosting->generalPostingByJournal((string) $settlement->journal_entry_id);
            if (! $general) {
                $adopted = $this->generalPosting->adoptExistingJournal((string) $settlement->journal_entry_id, $userId);
                $generalId = (string) $adopted['general_posting_id'];
            } else {
                $generalId = (string) $general->id;
            }
            $this->generalPosting->reopen($generalId, $reason, $userId);
            $link = DB::table('finance_general_posting_journals')
                ->where('general_posting_id', $generalId)
                ->where('journal_entry_id', (string) $settlement->journal_entry_id)
                ->first();
            $reversalId = (string) ($link->reversal_journal_id ?? '');
            $reversalNo = (string) ($link->reversal_journal_no ?? '');
            if ($reversalId === '') {
                throw new InvalidArgumentException('Reversal General Posting Settlement tidak ditemukan.');
            }
            DB::table('finance_settlements')->where('id', $id)->update([
                'status' => 'DRAFT',
                'reversal_journal_id' => $reversalId, 'reversal_journal_no' => $reversalNo,
                'reversed_at' => now(), 'reversed_by_user_id' => $userId,
                'journal_entry_id' => null, 'journal_no' => null,
                'posted_at' => null, 'posted_by_user_id' => null,
                'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            $this->recalculateSource((string) $settlement->settlement_source_id);
            return ['id' => $id, 'already_draft' => false, 'reversal_journal_id' => $reversalId, 'reversal_journal_no' => $reversalNo];
        });
    }

    public function deleteDraft(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $settlement = DB::table('finance_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $settlement) return;
            if ($settlement->status !== 'DRAFT') throw new InvalidArgumentException('Hanya Settlement DRAFT yang dapat dihapus/dibatalkan.');
            if ((int) $settlement->posting_version > 0) {
                DB::table('finance_settlements')->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            } else {
                DB::table('finance_settlements')->where('id', $id)->delete();
            }
            $this->recalculateSource((string) $settlement->settlement_source_id);
        });
    }

    public function recalculateSource(string $sourceId): void
    {
        $source = DB::table('finance_settlement_sources')->where('id', $sourceId)->first();
        if (! $source) return;
        $postedSingle = round((float) DB::table('finance_settlements')->where('settlement_source_id', $sourceId)->where('status', 'POSTED')->sum('clearing_amount'), 2);
        $postedBulk = \Illuminate\Support\Facades\Schema::hasTable('finance_settlement_bulk_items')
            ? round((float) DB::table('finance_settlement_bulk_items as bi')->join('finance_settlement_batches as b','b.id','=','bi.batch_id')->where('bi.settlement_source_id',$sourceId)->where('b.status','POSTED')->sum('bi.clearing_amount'),2)
            : 0.0;
        $posted = round($postedSingle + $postedBulk, 2);
        $outstanding = max(0, round((float) $source->original_amount - $posted, 2));
        $hasMapping = $this->resolveMapping((string) $source->company_code, (string) $source->outlet_id, $source->payment_method_id ? (string) $source->payment_method_id : null, (string) $source->payment_method_name, (string) $source->marking);
        $journalStatus = DB::table('finance_journal_entries')->where('id', $source->source_journal_entry_id)->value('status');
        $status = $journalStatus !== 'POSTED' ? 'SOURCE_REVERSED' : ($posted >= (float) $source->original_amount - 0.005 ? 'SETTLED' : ($posted > 0.005 ? 'PARTIAL' : ($hasMapping ? 'OPEN' : 'MAPPING_REQUIRED')));
        DB::table('finance_settlement_sources')->where('id', $sourceId)->update([
            'settled_amount' => $posted,
            'outstanding_amount' => $outstanding,
            'mapping_id' => $hasMapping?->id ? (string) $hasMapping->id : null,
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    public function availableAmount(string $sourceId, ?string $excludeSettlementId = null): float
    {
        $source = DB::table('finance_settlement_sources')->where('id', $sourceId)->first();
        if (! $source) return 0.0;
        $usedSingle = DB::table('finance_settlements')->where('settlement_source_id', $sourceId)
            ->whereIn('status', ['DRAFT', 'POSTED'])
            ->when($excludeSettlementId, fn ($q, $id) => $q->where('id', '<>', $id))
            ->sum('clearing_amount');
        $usedBulk = \Illuminate\Support\Facades\Schema::hasTable('finance_settlement_bulk_items')
            ? DB::table('finance_settlement_bulk_items as bi')->join('finance_settlement_batches as b','b.id','=','bi.batch_id')
                ->where('bi.settlement_source_id',$sourceId)->whereIn('b.status',['DRAFT','POSTED'])->sum('bi.clearing_amount')
            : 0;
        return max(0, round((float) $source->original_amount - (float) $usedSingle - (float) $usedBulk, 2));
    }

    public function mappingForSource(object $source): ?object
    {
        return $this->resolveMapping((string) $source->company_code, (string) $source->outlet_id, $source->payment_method_id ? (string) $source->payment_method_id : null, (string) $source->payment_method_name, (string) $source->marking);
    }

    private function resolveMapping(string $company, string $outletId, ?string $paymentMethodId, string $paymentName, string $marking): ?object
    {
        $rows = DB::table('finance_settlement_mappings')->where('is_active', true)->where('company_code', $company)
            ->where(function ($q) use ($paymentMethodId, $paymentName): void {
                if ($paymentMethodId) $q->where('payment_method_id', $paymentMethodId)->orWhere(function ($q2) use ($paymentName): void { $q2->whereNull('payment_method_id')->whereRaw('LOWER(payment_method_name) = ?', [mb_strtolower($paymentName)]); });
                else $q->whereRaw('LOWER(payment_method_name) = ?', [mb_strtolower($paymentName)]);
            })
            ->where(function ($q) use ($outletId): void { $q->where('outlet_id', $outletId)->orWhereNull('outlet_id'); })
            ->whereIn('marking', [$marking, 'ALL'])->get();
        return $rows->sortByDesc(function ($row) use ($outletId, $paymentMethodId, $marking): int {
            $score = 0;
            if ((string) $row->outlet_id === $outletId) $score += 8;
            if ($paymentMethodId && (string) $row->payment_method_id === $paymentMethodId) $score += 4;
            if ((string) $row->marking === $marking) $score += 2;
            return $score;
        })->first();
    }

    private function resolveClearingLine(string $journalId, string $paymentName, string $marking, float $amount): object
    {
        $lines = DB::table('finance_journal_entry_lines')->where('journal_entry_id', $journalId)->where('debit', '>', 0.005)->orderBy('line_no')->get();
        $exact = $lines->first(fn ($line) => str_contains(mb_strtolower((string) $line->description), mb_strtolower($paymentName)) && str_contains(mb_strtoupper((string) $line->description), $marking));
        if ($exact) return $exact;
        $unsettled = $lines->filter(fn ($line) => str_contains(mb_strtolower((string) $line->account_name.' '.(string) $line->description), 'dana belum disetor'));
        if ($unsettled->count() === 1) return $unsettled->first();
        $byAmount = $unsettled->first(fn ($line) => abs((float) $line->debit - $amount) <= 0.005);
        if ($byAmount) return $byAmount;
        throw new InvalidArgumentException("Tidak dapat menentukan COA Dana Belum Disetor untuk {$paymentName} {$marking} pada journal {$journalId}. Pastikan template Reconciliation memakai akun clearing dengan nama/deskripsi 'Dana Belum Disetor'.");
    }

    private function journalPreview(object $settlement, object $source): array
    {
        $this->assertAmounts((float) $settlement->clearing_amount, (float) $settlement->bank_received_amount, (float) $settlement->mdr_amount, (float) $settlement->admin_fee_amount);
        $lines = [];
        if ((float) $settlement->bank_received_amount > 0.005) $lines[] = ['account_id' => (string) $settlement->bank_account_id, 'description' => 'Penerimaan '.$settlement->payment_method_name.' · '.$settlement->settlement_no, 'debit' => (float) $settlement->bank_received_amount, 'credit' => 0];
        if ((float) $settlement->mdr_amount > 0.005) {
            if (! $settlement->mdr_expense_account_id) throw new InvalidArgumentException('COA MDR belum dimapping.');
            $lines[] = ['account_id' => (string) $settlement->mdr_expense_account_id, 'description' => 'MDR '.$settlement->payment_method_name.' · '.$settlement->settlement_no, 'debit' => (float) $settlement->mdr_amount, 'credit' => 0];
        }
        if ((float) $settlement->admin_fee_amount > 0.005) {
            if (! $settlement->admin_fee_expense_account_id) throw new InvalidArgumentException('COA Admin Fee belum dimapping.');
            $lines[] = ['account_id' => (string) $settlement->admin_fee_expense_account_id, 'description' => 'Admin Fee '.$settlement->payment_method_name.' · '.$settlement->settlement_no, 'debit' => (float) $settlement->admin_fee_amount, 'credit' => 0];
        }
        $lines[] = ['account_id' => (string) $source->clearing_account_id, 'description' => 'Clear Dana Belum Disetor '.$settlement->payment_method_name.' · '.$settlement->marking, 'debit' => 0, 'credit' => (float) $settlement->clearing_amount];

        $accountIds = collect($lines)->pluck('account_id')->unique()->all();
        $accounts = DB::table('finance_chart_of_accounts')->whereIn('id', $accountIds)->get(['id','code','name','account_type'])->keyBy('id');
        $viewLines = collect($lines)->map(function ($line) use ($accounts): array {
            $account = $accounts->get($line['account_id']);
            return $line + ['account_code' => (string) ($account->code ?? ''), 'account_name' => (string) ($account->name ?? ''), 'account_type' => (string) ($account->account_type ?? '')];
        })->values()->all();
        $debit = round(array_sum(array_column($viewLines, 'debit')), 2);
        $credit = round(array_sum(array_column($viewLines, 'credit')), 2);
        return ['lines' => $viewLines, 'total_debit' => $debit, 'total_credit' => $credit, 'balanced' => abs($debit - $credit) <= 0.005];
    }

    private function assertSourceActive(object $source): void
    {
        $journal = DB::table('finance_journal_entries')->where('id', $source->source_journal_entry_id)->first(['status','reversal_journal_id']);
        if (! $journal || $journal->status !== 'POSTED' || $journal->reversal_journal_id) throw new InvalidArgumentException('Source Reconciliation sudah direversal/tidak aktif dan tidak dapat disettle.');
        $reconciliation = DB::table('finance_reconciliations')->where('id', $source->reconciliation_id)->first(['status','posting_version']);
        if (! $reconciliation || $reconciliation->status !== 'POSTED' || (int) $reconciliation->posting_version !== (int) $source->posting_version) throw new InvalidArgumentException('Source Reconciliation bukan posting version aktif. Refresh daftar Settlement.');
    }

    private function assertAmounts(float $gross, float $bank, float $mdr, float $admin): void
    {
        if ($gross <= 0.005) throw new InvalidArgumentException('Gross clearing harus lebih dari 0.');
        foreach (['Bank diterima' => $bank, 'MDR' => $mdr, 'Admin Fee' => $admin] as $name => $amount) if ($amount < -0.005) throw new InvalidArgumentException("{$name} tidak boleh negatif.");
        $sum = round($bank + $mdr + $admin, 2);
        if (abs($sum - round($gross, 2)) > 0.005) throw new InvalidArgumentException('Bank diterima + MDR + Admin Fee harus sama dengan Gross Dana Belum Disetor yang disettle.');
    }

    private function assertAccount(string $id, array $types, string $label): void
    {
        $row = DB::table('finance_chart_of_accounts')->where('id', $id)->where('is_active', true)->where('is_postable', true)->first(['account_type']);
        if (! $row || ! in_array(strtoupper((string) $row->account_type), array_map('strtoupper', $types), true)) throw new InvalidArgumentException("{$label} harus memakai COA aktif/postable tipe ".implode('/', $types).'.');
    }

    private function assertExpenseAccount(string $id, string $label): void
    {
        $row = DB::table('finance_chart_of_accounts')->where('id', $id)->where('is_active', true)->where('is_postable', true)->first(['account_type']);
        if (! $row || ! in_array(strtoupper((string) $row->account_type), ['EXPENSE','OTHER_EXPENSE'], true)) throw new InvalidArgumentException("{$label} harus memakai COA Expense/Other Expense aktif dan postable.");
    }

    private function nextNumber(string $date): string
    {
        $prefix = 'STL-'.str_replace('-', '', $date).'-';
        $last = DB::table('finance_settlements')->where('settlement_no', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('settlement_no')->value('settlement_no');
        $seq = $last ? ((int) substr((string) $last, -4) + 1) : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
