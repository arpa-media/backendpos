<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceManualJournalUnifiedPostingService
{
    public function __construct(
        private readonly FinanceGeneralPostingService $generalPosting,
    ) {}

    /**
     * Convert a legacy Manual Journal DRAFT into a General Posting MANUAL snapshot,
     * then let FinanceGeneralPostingService become the only component that creates/posts GL.
     *
     * The legacy DRAFT is deleted after the General Posting is successfully posted.
     */
    public function postDraft(string $journalId, ?string $userId): array
    {
        return DB::transaction(function () use ($journalId, $userId): array {
            $journal = DB::table('finance_journal_entries')->where('id', $journalId)->lockForUpdate()->first();
            if (! $journal) {
                throw new InvalidArgumentException('Draft Manual Journal tidak ditemukan.');
            }
            if ((string) $journal->source_type !== 'MANUAL') {
                throw new InvalidArgumentException('Hanya draft MANUAL yang dapat dikonversi ke General Posting.');
            }
            if ((string) $journal->status !== 'DRAFT') {
                throw new InvalidArgumentException('Hanya Manual Journal DRAFT yang dapat dipost melalui General Posting.');
            }

            $lines = DB::table('finance_journal_entry_lines')
                ->where('journal_entry_id', $journalId)
                ->orderBy('line_no')
                ->get(['account_id', 'description', 'debit', 'credit'])
                ->map(fn ($line) => [
                    'account_id' => (string) $line->account_id,
                    'description' => $line->description ? (string) $line->description : null,
                    'debit' => round((float) $line->debit, 2),
                    'credit' => round((float) $line->credit, 2),
                ])->all();

            if (count($lines) < 2) {
                throw new InvalidArgumentException('Manual Journal minimal mempunyai 2 baris.');
            }
            $debit = round((float) collect($lines)->sum('debit'), 2);
            $credit = round((float) collect($lines)->sum('credit'), 2);
            if ($debit <= 0 || abs($debit - $credit) > 0.01) {
                throw new InvalidArgumentException("Manual Journal tidak balance. Debit {$debit}, Credit {$credit}.");
            }

            $sourceKey = 'MANUAL_JOURNAL:'.$journalId;
            $existing = DB::table('finance_general_postings')->where('source_key', $sourceKey)->lockForUpdate()->first();

            if ($existing && (string) $existing->status === 'POSTED') {
                $link = DB::table('finance_general_posting_journals')
                    ->where('general_posting_id', $existing->id)
                    ->where('posting_version', $existing->posting_version)
                    ->first();

                // Remove stale legacy draft if a prior retry already completed the GP post.
                DB::table('finance_journal_entries')->where('id', $journalId)->where('status', 'DRAFT')->delete();

                return [
                    'general_posting_id' => (string) $existing->id,
                    'journal_entry_id' => $link?->journal_entry_id ? (string) $link->journal_entry_id : null,
                    'journal_no' => $link?->journal_no ? (string) $link->journal_no : null,
                    'status' => 'POSTED',
                    'idempotent' => true,
                ];
            }

            $template = DB::table('finance_posting_templates')
                ->where('code', 'SYS-GENERAL-AUTO-SNAPSHOT')
                ->where('source_type', 'GENERAL')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            if (! $template) {
                throw new InvalidArgumentException('System template Unified General Posting belum tersedia. Apply Finance Unified Posting F01.');
            }

            $businessDate = (string) ($journal->business_date ?: $journal->journal_date);
            $rowId = (string) ($existing->id ?? Str::ulid());
            $metadata = [
                'posting_origin' => 'MANUAL',
                'posting_mode' => 'SNAPSHOT',
                'source_module' => 'MANUAL_JOURNAL',
                'source_identity' => $journalId,
                'snapshot_lines' => $lines,
                'unified_posting_version' => 'F04',
                'legacy_manual_draft_id' => $journalId,
                'legacy_manual_journal_no' => (string) $journal->journal_no,
            ];

            $payload = [
                'source_key' => $sourceKey,
                'source_code' => 'MANUAL_JOURNAL',
                'reference_no' => $journal->reference_no ? (string) $journal->reference_no : (string) $journal->journal_no,
                'company_code' => (string) $journal->company_code,
                'outlet_id' => $journal->outlet_id ? (string) $journal->outlet_id : null,
                'marking' => (string) $journal->marking,
                'template_id' => (string) $template->id,
                'business_date' => $businessDate,
                'journal_date' => (string) $journal->journal_date,
                'description' => trim((string) ($journal->description ?: 'Manual Journal '.$journal->journal_no)),
                'amount' => $debit,
                'subtotal' => $debit,
                'tax' => 0,
                'discount' => 0,
                'rounding' => 0,
                'mdr' => 0,
                'admin_fee' => 0,
                'payable' => $debit,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ];

            if ($existing) {
                if ((string) $existing->status !== 'DRAFT') {
                    throw new InvalidArgumentException('General Posting Manual existing tidak berada pada status DRAFT/POSTED.');
                }
                DB::table('finance_general_postings')->where('id', $rowId)->update($payload);
            } else {
                DB::table('finance_general_postings')->insert($payload + [
                    'id' => $rowId,
                    'posting_no' => 'GEN-'.str_replace('-', '', $businessDate).'-'.strtoupper(substr($rowId, -8)),
                    'status' => 'DRAFT',
                    'posting_version' => 0,
                    'source_fingerprint' => 'PENDING',
                    'created_by_user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            // FinanceGeneralPostingService owns fingerprint validation and GL creation.
            $this->generalPosting->refresh($rowId, $userId);
            $posted = $this->generalPosting->postOne($rowId, $userId);

            // The legacy manual draft is staging-only. Once GP posts successfully, remove it
            // so it cannot later be mistaken for another direct-to-GL journal.
            DB::table('finance_journal_entries')->where('id', $journalId)->where('status', 'DRAFT')->delete();

            return [
                'general_posting_id' => $rowId,
                'journal_entry_id' => $posted['journal_entry_id'] ?? null,
                'journal_no' => $posted['journal_no'] ?? null,
                'status' => 'POSTED',
                'idempotent' => (bool) ($posted['idempotent'] ?? false),
            ];
        }, 3);
    }

    /**
     * Reversal of any historical Manual Journal is delegated to General Posting.
     * Legacy POSTED manual journals are adopted first; no duplicate GL is created.
     */
    public function reversePosted(string $journalId, string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($journalId, $reason, $userId): array {
            $journal = DB::table('finance_journal_entries')->where('id', $journalId)->lockForUpdate()->first();
            if (! $journal) {
                throw new InvalidArgumentException('Manual Journal tidak ditemukan.');
            }
            if ((string) $journal->status === 'REVERSED' && $journal->reversal_journal_id) {
                return [
                    'general_posting_id' => null,
                    'reversal_journal_id' => (string) $journal->reversal_journal_id,
                    'idempotent' => true,
                ];
            }
            if ((string) $journal->status !== 'POSTED') {
                throw new InvalidArgumentException('Hanya Manual Journal POSTED yang dapat direversal.');
            }

            $general = $this->generalPosting->generalPostingByJournal($journalId);
            $generalId = $general
                ? (string) $general->id
                : (string) $this->generalPosting->adoptExistingJournal($journalId, $userId)['general_posting_id'];

            $this->generalPosting->reopen($generalId, $reason, $userId);

            $link = DB::table('finance_general_posting_journals')
                ->where('general_posting_id', $generalId)
                ->where('journal_entry_id', $journalId)
                ->first();

            if (! $link || ! $link->reversal_journal_id) {
                throw new InvalidArgumentException('Reversal General Posting Manual Journal tidak ditemukan.');
            }

            return [
                'general_posting_id' => $generalId,
                'reversal_journal_id' => (string) $link->reversal_journal_id,
                'reversal_journal_no' => $link->reversal_journal_no ? (string) $link->reversal_journal_no : null,
                'idempotent' => false,
            ];
        }, 3);
    }
}
