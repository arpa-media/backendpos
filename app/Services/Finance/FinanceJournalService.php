<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceJournalService
{
    public function __construct(private readonly FinanceScopeResolver $scopeResolver)
    {
    }

    public function createManual(array $payload, ?string $userId): string
    {
        return $this->persistManual($payload, null, $userId);
    }

    public function updateManual(string $id, array $payload, ?string $userId): string
    {
        return $this->persistManual($payload, $id, $userId);
    }

    public function createDraft(array $header, array $lines, ?string $userId): string
    {
        $this->assertGeneralPostingCaller('create journal draft');
        $sourceType = strtoupper(trim((string) ($header['source_type'] ?? 'GENERAL')));
        if ($sourceType !== 'GENERAL') {
            throw new InvalidArgumentException('F04 Unified Posting Guard: journal accounting hanya boleh dibuat oleh General Posting (source_type GENERAL).');
        }

        $scope = $this->scopeResolver->resolve($header['company_code'] ?? null, $header['outlet_id'] ?? null);
        $normalizedLines = $this->normalizeLines($lines);
        [$totalDebit, $totalCredit] = $this->totals($normalizedLines);
        $this->assertBalanced($totalDebit, $totalCredit);

        return DB::transaction(function () use ($header, $normalizedLines, $totalDebit, $totalCredit, $scope, $userId, $sourceType): string {
            $sourceKey = $this->nullableString($header['source_key'] ?? null);
            if ($sourceKey) {
                $existing = DB::table('finance_journal_entries')->where('source_key', $sourceKey)->lockForUpdate()->value('id');
                if ($existing) {
                    return (string) $existing;
                }
            }

            $id = (string) Str::ulid();
            $journalDate = (string) ($header['journal_date'] ?? now()->toDateString());
            DB::table('finance_journal_entries')->insert([
                'id' => $id,
                'journal_no' => $this->nextJournalNo($journalDate),
                'journal_date' => $journalDate,
                'business_date' => (string) ($header['business_date'] ?? $journalDate),
                'company_code' => $scope['company_code'],
                'outlet_id' => $scope['outlet_id'],
                'marking' => $this->normalizeMarking($header['marking'] ?? 'MARKING'),
                'source_type' => $sourceType,
                'source_id' => $this->nullableString($header['source_id'] ?? null),
                'source_key' => $sourceKey,
                'reference_no' => $this->nullableString($header['reference_no'] ?? null),
                'description' => $this->nullableString($header['description'] ?? null),
                'status' => 'DRAFT',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'reversal_of_journal_id' => $this->nullableString($header['reversal_of_journal_id'] ?? null),
                'reversal_journal_id' => null,
                'posted_at' => null,
                'posted_by_user_id' => null,
                'reversed_at' => null,
                'reversed_by_user_id' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'source_meta' => isset($header['source_meta']) ? json_encode($header['source_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertLines($id, $normalizedLines);
            return $id;
        });
    }

    public function post(string $id, ?string $userId): void
    {
        $this->assertGeneralPostingCaller('post journal');
        DB::transaction(function () use ($id, $userId): void {
            $entry = DB::table('finance_journal_entries')->where('id', $id)->lockForUpdate()->first();
            if (! $entry) {
                throw new InvalidArgumentException('Jurnal tidak ditemukan.');
            }
            if ($entry->status !== 'DRAFT') {
                throw new InvalidArgumentException('Hanya jurnal DRAFT yang dapat diposting.');
            }
            if ((string) $entry->source_type !== 'GENERAL') {
                throw new InvalidArgumentException('F04 Unified Posting Guard: posting GL hanya boleh dilakukan oleh General Posting.');
            }

            $lines = DB::table('finance_journal_entry_lines')->where('journal_entry_id', $id)->get(['debit', 'credit']);
            if ($lines->count() < 2) {
                throw new InvalidArgumentException('Jurnal minimal harus mempunyai 2 baris.');
            }
            $debit = round((float) $lines->sum('debit'), 2);
            $credit = round((float) $lines->sum('credit'), 2);
            $this->assertBalanced($debit, $credit);

            DB::table('finance_journal_entries')->where('id', $id)->update([
                'status' => 'POSTED',
                'total_debit' => $debit,
                'total_credit' => $credit,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
        });
    }

    public function reverse(string $id, string $reversalDate, ?string $reason, ?string $userId): string
    {
        $this->assertGeneralPostingCaller('reverse journal');
        return DB::transaction(function () use ($id, $reversalDate, $reason, $userId): string {
            $entry = DB::table('finance_journal_entries')->where('id', $id)->lockForUpdate()->first();
            if (! $entry) {
                throw new InvalidArgumentException('Jurnal tidak ditemukan.');
            }
            if ($entry->status !== 'POSTED') {
                throw new InvalidArgumentException('Hanya jurnal POSTED yang dapat direversal.');
            }
            if ($entry->reversal_journal_id) {
                return (string) $entry->reversal_journal_id;
            }
            if ($entry->reversal_of_journal_id) {
                throw new InvalidArgumentException('Jurnal reversal tidak dapat direversal dari menu ini. Buat jurnal koreksi baru bila diperlukan.');
            }
            $hasGeneralPosting = DB::table('finance_general_posting_journals')
                ->where('journal_entry_id', $id)
                ->exists();
            if (! $hasGeneralPosting) {
                throw new InvalidArgumentException('F04 Unified Posting Guard: journal belum mempunyai parent General Posting. Jalankan reconcile/adopt terlebih dahulu sebelum reversal.');
            }

            $originalLines = DB::table('finance_journal_entry_lines')
                ->where('journal_entry_id', $id)
                ->orderBy('line_no')
                ->get();
            if ($originalLines->count() < 2) {
                throw new InvalidArgumentException('Baris jurnal asal tidak lengkap.');
            }

            $reversalId = (string) Str::ulid();
            $description = trim((string) ($reason ?: 'Reversal '.$entry->journal_no));
            DB::table('finance_journal_entries')->insert([
                'id' => $reversalId,
                'journal_no' => $this->nextJournalNo($reversalDate),
                'journal_date' => $reversalDate,
                'business_date' => (string) $entry->business_date,
                'company_code' => (string) $entry->company_code,
                'outlet_id' => $entry->outlet_id,
                'marking' => (string) $entry->marking,
                'source_type' => 'REVERSAL',
                'source_id' => (string) $entry->id,
                'source_key' => 'REVERSAL:'.(string) $entry->id,
                'reference_no' => 'REV-'.(string) $entry->journal_no,
                'description' => $description,
                'status' => 'POSTED',
                'total_debit' => (float) $entry->total_credit,
                'total_credit' => (float) $entry->total_debit,
                'reversal_of_journal_id' => (string) $entry->id,
                'reversal_journal_id' => null,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'reversed_at' => null,
                'reversed_by_user_id' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'source_meta' => json_encode(['original_source_type' => $entry->source_type, 'original_reference_no' => $entry->reference_no], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reversalLines = $originalLines->map(fn ($line) => [
                'account_id' => (string) $line->account_id,
                'account_code' => (string) $line->account_code,
                'account_name' => (string) $line->account_name,
                'account_type' => (string) $line->account_type,
                'normal_balance' => (string) $line->normal_balance,
                'description' => $description,
                'debit' => round((float) $line->credit, 2),
                'credit' => round((float) $line->debit, 2),
            ])->all();
            $this->insertLines($reversalId, $reversalLines);

            DB::table('finance_journal_entries')->where('id', $id)->update([
                'status' => 'REVERSED',
                'reversal_journal_id' => $reversalId,
                'reversed_at' => now(),
                'reversed_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            return $reversalId;
        });
    }

    public function deleteDraft(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $entry = DB::table('finance_journal_entries')->where('id', $id)->lockForUpdate()->first();
            if (! $entry) {
                return;
            }
            if ($entry->status !== 'DRAFT') {
                throw new InvalidArgumentException('Hanya jurnal DRAFT yang boleh dihapus. Jurnal posted harus dikoreksi melalui reversal.');
            }
            if ($entry->source_type !== 'MANUAL') {
                throw new InvalidArgumentException('Hanya jurnal MANUAL DRAFT yang dapat dihapus dari menu Manual Journal.');
            }
            DB::table('finance_journal_entries')->where('id', $id)->delete();
        });
    }

    /**
     * Hard runtime boundary: only FinanceGeneralPostingService may create/post/reverse
     * accounting journals. Manual Journal may still keep non-GL drafts, but posting those
     * drafts must first convert them to a General Posting envelope.
     */
    private function assertGeneralPostingCaller(string $operation): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = (string) ($trace[2]['class'] ?? '');
        if ($caller !== FinanceGeneralPostingService::class) {
            throw new InvalidArgumentException(
                'F04 Unified Posting Guard: '.$operation.' hanya boleh dipanggil oleh FinanceGeneralPostingService.'
            );
        }
    }

    private function persistManual(array $payload, ?string $id, ?string $userId): string
    {
        $scope = $this->scopeResolver->resolve($payload['company_code'] ?? null, $payload['outlet_id'] ?? null);
        $lines = $this->normalizeLines($payload['lines'] ?? []);
        [$totalDebit, $totalCredit] = $this->totals($lines);
        $this->assertBalanced($totalDebit, $totalCredit);

        return DB::transaction(function () use ($payload, $id, $userId, $scope, $lines, $totalDebit, $totalCredit): string {
            $entryId = $id ?: (string) Str::ulid();
            $journalDate = (string) $payload['journal_date'];

            if ($id) {
                $existing = DB::table('finance_journal_entries')->where('id', $id)->lockForUpdate()->first();
                if (! $existing) {
                    throw new InvalidArgumentException('Jurnal tidak ditemukan.');
                }
                if ($existing->status !== 'DRAFT') {
                    throw new InvalidArgumentException('Jurnal yang sudah POSTED/REVERSED bersifat immutable. Gunakan reversal untuk koreksi.');
                }
                if ($existing->source_type !== 'MANUAL') {
                    throw new InvalidArgumentException('Jurnal dari source otomatis tidak dapat diedit dari Manual Journal.');
                }

                DB::table('finance_journal_entries')->where('id', $id)->update([
                    'journal_date' => $journalDate,
                    'business_date' => (string) ($payload['business_date'] ?? $journalDate),
                    'company_code' => $scope['company_code'],
                    'outlet_id' => $scope['outlet_id'],
                    'marking' => $this->normalizeMarking($payload['marking'] ?? 'MARKING'),
                    'reference_no' => $this->nullableString($payload['reference_no'] ?? null),
                    'description' => $this->nullableString($payload['description'] ?? null),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
                DB::table('finance_journal_entry_lines')->where('journal_entry_id', $id)->delete();
            } else {
                DB::table('finance_journal_entries')->insert([
                    'id' => $entryId,
                    'journal_no' => $this->nextJournalNo($journalDate),
                    'journal_date' => $journalDate,
                    'business_date' => (string) ($payload['business_date'] ?? $journalDate),
                    'company_code' => $scope['company_code'],
                    'outlet_id' => $scope['outlet_id'],
                    'marking' => $this->normalizeMarking($payload['marking'] ?? 'MARKING'),
                    'source_type' => 'MANUAL',
                    'source_id' => null,
                    'source_key' => null,
                    'reference_no' => $this->nullableString($payload['reference_no'] ?? null),
                    'description' => $this->nullableString($payload['description'] ?? null),
                    'status' => 'DRAFT',
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'reversal_of_journal_id' => null,
                    'reversal_journal_id' => null,
                    'posted_at' => null,
                    'posted_by_user_id' => null,
                    'reversed_at' => null,
                    'reversed_by_user_id' => null,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'source_meta' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->insertLines($entryId, $lines);
            return $entryId;
        });
    }

    private function normalizeLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Jurnal minimal harus mempunyai 2 baris.');
        }

        $accountIds = collect($lines)->pluck('account_id')->filter()->unique()->values()->all();
        $accounts = DB::table('finance_chart_of_accounts')
            ->whereIn('id', $accountIds)
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_active', 'is_postable'])
            ->keyBy('id');

        $normalized = [];
        foreach (array_values($lines) as $index => $line) {
            $accountId = trim((string) ($line['account_id'] ?? ''));
            $account = $accounts->get($accountId);
            if (! $account || ! $account->is_active || ! $account->is_postable) {
                throw new InvalidArgumentException('COA baris '.($index + 1).' tidak aktif, tidak ditemukan, atau bukan postable account.');
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if (($debit <= 0 && $credit <= 0) || ($debit > 0 && $credit > 0)) {
                throw new InvalidArgumentException('Baris '.($index + 1).' wajib hanya memiliki nilai DEBIT atau CREDIT.');
            }

            $normalized[] = [
                'account_id' => (string) $account->id,
                'account_code' => (string) $account->code,
                'account_name' => (string) $account->name,
                'account_type' => (string) $account->account_type,
                'normal_balance' => (string) $account->normal_balance,
                'description' => $this->nullableString($line['description'] ?? null),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $normalized;
    }

    private function insertLines(string $entryId, array $lines): void
    {
        $now = now();
        DB::table('finance_journal_entry_lines')->insert(array_map(fn (array $line, int $index) => [
            'id' => (string) Str::ulid(),
            'journal_entry_id' => $entryId,
            'account_id' => $line['account_id'],
            'line_no' => $index + 1,
            'account_code' => $line['account_code'],
            'account_name' => $line['account_name'],
            'account_type' => $line['account_type'],
            'normal_balance' => $line['normal_balance'],
            'description' => $line['description'] ?? null,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines, array_keys($lines)));
    }

    private function totals(array $lines): array
    {
        return [
            round(array_sum(array_column($lines, 'debit')), 2),
            round(array_sum(array_column($lines, 'credit')), 2),
        ];
    }

    private function assertBalanced(float $debit, float $credit): void
    {
        if ($debit <= 0 || $credit <= 0 || abs($debit - $credit) > 0.005) {
            throw new InvalidArgumentException('Total DEBIT dan CREDIT harus balance dan lebih besar dari 0.');
        }
    }

    private function normalizeMarking(mixed $marking): string
    {
        $marking = strtoupper(trim((string) $marking));
        if (! in_array($marking, FinanceScopeResolver::MARKINGS, true)) {
            throw new InvalidArgumentException('Marking harus MARKING atau UNMARKING.');
        }
        return $marking;
    }

    private function nextJournalNo(string $date): string
    {
        $prefix = 'FJ-'.str_replace('-', '', substr($date, 0, 10));
        $last = DB::table('finance_journal_entries')
            ->where('journal_no', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('journal_no')
            ->value('journal_no');
        $sequence = 1;
        if ($last && preg_match('/-(\d+)$/', (string) $last, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }
        return $prefix.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
