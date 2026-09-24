<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Services\Finance\FinanceGeneralPostingService;
use App\Services\Finance\FinancePurchasingPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PurchasingRealizationPostingService
{
    public const SOURCE_CODE = 'PUR_REALIZATION';

    public function __construct(
        private readonly ExecutionWorkflowCatalog $catalog,
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly FinancePurchasingPostingService $financePosting,
        private readonly PurchasingDocumentAttachmentService $attachments,
    ) {
    }

    /** @return array<string,mixed> */
    public function options(
        string $kind,
        string $id,
        ?string $companyOverride = null,
        ?string $markingOverride = null,
    ): array {
        $d = $this->catalog->definition($kind);
        $execution = $this->execution($d, $id);
        $date = (string) ($execution->realization_date ?: $execution->document_date ?: now()->toDateString());
        $company = $this->resolveCompany(
            $execution->outlet_id ? (string) $execution->outlet_id : null,
            $date,
            $companyOverride ?: ($execution->company_code ?? null),
        );
        $marking = $this->normalizeMarking($markingOverride ?: ($execution->marking ?? 'UNMARKING'));

        $templates = DB::table('finance_posting_templates as t')
            ->leftJoin('outlets as o', 'o.id', '=', 't.outlet_id')
            ->where('t.source_type', 'GENERAL')
            ->where('t.is_active', true)
            ->whereNull('t.deleted_at')
            ->where(function ($query) use ($company): void {
                $query->whereNull('t.company_code');
                if ($company !== null) {
                    $query->orWhere('t.company_code', $company);
                }
            })
            ->where(function ($query) use ($execution): void {
                $query->whereNull('t.outlet_id');
                if ($execution->outlet_id) {
                    $query->orWhere('t.outlet_id', (string) $execution->outlet_id);
                }
            })
            ->where(function ($query) use ($marking): void {
                $query->whereNull('t.marking')->orWhere('t.marking', $marking);
            })
            ->orderByRaw('CASE WHEN t.outlet_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN t.company_code IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN t.marking IS NULL THEN 1 ELSE 0 END')
            ->orderBy('t.code')
            ->get([
                't.id', 't.code', 't.name', 't.description', 't.company_code',
                't.outlet_id', 't.marking', 'o.name as outlet_name',
            ])
            ->map(function ($row): array {
                $lineCount = DB::table('finance_posting_template_lines')
                    ->where('template_id', $row->id)
                    ->count();

                return [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'description' => $row->description,
                    'company_code' => $row->company_code ? (string) $row->company_code : null,
                    'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
                    'outlet_name' => $row->outlet_name,
                    'marking' => $row->marking ? (string) $row->marking : null,
                    'line_count' => $lineCount,
                ];
            })
            ->all();

        return [
            'companies' => DB::table('finance_companies')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn ($row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
                ->all(),
            'resolved_company_code' => $company,
            'company_locked_by_outlet' => (bool) $execution->outlet_id,
            'marking' => $marking,
            'markings' => ['MARKING', 'UNMARKING'],
            'templates' => $templates,
            'attachment_required' => true,
            'posting_source_code' => self::SOURCE_CODE,
            'posting_mode' => 'AUTO_ON_FINALIZE',
        ];
    }

    /**
     * Synchronize the Execution realization into one Finance General Posting DRAFT.
     * It deliberately does not POST the General Posting.
     *
     * @return array<string,mixed>|null
     */
    public function syncDraft(string $kind, string $id, ?User $actor = null): ?array
    {
        $d = $this->catalog->definition($kind);

        return DB::transaction(function () use ($d, $id, $actor): ?array {
            $execution = $this->execution($d, $id, true);
            if ((string) $execution->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'status' => 'General Posting realisasi hanya dapat disinkronkan selama Execution berstatus DRAFT.',
                ]);
            }

            $templateId = trim((string) ($execution->posting_template_id ?? ''));
            $amount = round((float) ($execution->actual_total_amount ?? 0), 2);

            if ($templateId === '' || $amount <= 0) {
                $this->detachUnusedDraft($d, $execution);
                DB::table($d['table'])->where('id', $id)->update([
                    'realization_status' => 'PENDING',
                    'realization_fingerprint' => null,
                    'updated_at' => now(),
                ]);
                return null;
            }

            $realizationDate = trim((string) ($execution->realization_date ?? ''));
            if ($realizationDate === '') {
                throw ValidationException::withMessages(['realization_date' => 'Tanggal realisasi wajib diisi.']);
            }

            $company = $this->resolveCompany(
                $execution->outlet_id ? (string) $execution->outlet_id : null,
                $realizationDate,
                $execution->company_code ?? null,
            );
            if ($company === null) {
                throw ValidationException::withMessages([
                    'company_code' => 'PT wajib dipilih untuk realisasi corporate/non-outlet.',
                ]);
            }

            $marking = $this->normalizeMarking($execution->marking ?? 'UNMARKING');
            $template = $this->validateTemplate(
                $templateId,
                $company,
                $execution->outlet_id ? (string) $execution->outlet_id : null,
                $marking,
            );

            $generalPostingId = $execution->general_posting_id ? (string) $execution->general_posting_id : null;
            if ($generalPostingId) {
                $existing = DB::table('finance_general_postings')->where('id', $generalPostingId)->first();
                if ($existing && (string) $existing->status !== 'DRAFT') {
                    throw ValidationException::withMessages([
                        'general_posting_id' => 'General Posting realisasi sudah tidak DRAFT dan tidak dapat diubah dari Purchasing.',
                    ]);
                }
                if (! $existing) {
                    $generalPostingId = null;
                }
            }

            $number = (string) $execution->{$d['number']};
            $sourceKey = 'PUR-REAL:'.$d['kind'].':'.$id;
            $description = sprintf(
                'Realisasi %s %s%s',
                $d['label'],
                $number,
                $execution->external_reference ? ' · '.(string) $execution->external_reference : ''
            );

            try {
                $generalPostingId = $this->generalPosting->save([
                    'source_key' => $sourceKey,
                    'source_code' => self::SOURCE_CODE,
                    'reference_no' => $number,
                    'company_code' => $company,
                    'outlet_id' => $execution->outlet_id ? (string) $execution->outlet_id : null,
                    'marking' => $marking,
                    'template_id' => (string) $template->id,
                    'business_date' => $realizationDate,
                    'journal_date' => $realizationDate,
                    'description' => $description,
                    'amount' => $amount,
                    'subtotal' => round((float) ($execution->actual_subtotal ?? 0), 2),
                    'tax' => round((float) ($execution->actual_tax_amount ?? 0), 2),
                    'discount' => 0,
                    'rounding' => 0,
                    'mdr' => 0,
                    'admin_fee' => 0,
                    'payable' => $amount,
                    'metadata' => [
                        'execution_preview_only' => true,
                        'execution_kind' => $d['kind'],
                        'execution_id' => $id,
                        'execution_number' => $number,
                        'order_kind' => $d['order_kind'],
                        'order_id' => (string) $execution->order_id,
                        'fund_request_id' => $execution->fund_request_id ? (string) $execution->fund_request_id : null,
                        'actual_total_amount' => $amount,
                    ],
                ], $actor?->id, $generalPostingId);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['posting_template_id' => $e->getMessage()]);
            }

            $fingerprint = $this->fingerprint($execution, $company, $marking, (string) $template->id);
            DB::table($d['table'])->where('id', $id)->update([
                'company_code' => $company,
                'marking' => $marking,
                'general_posting_id' => $generalPostingId,
                'realization_status' => 'READY',
                'realization_fingerprint' => $fingerprint,
                'updated_at' => now(),
            ]);

            return $this->generalPosting->show($generalPostingId);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function summary(string $kind, string $id): array
    {
        $d = $this->catalog->definition($kind);
        $execution = $this->execution($d, $id);

        $attachmentType = $this->attachmentType($d['kind']);
        $attachments = $this->attachments->list($attachmentType, $id);
        $generalPosting = null;
        $stale = false;
        $staleReason = null;

        if ($execution->general_posting_id) {
            try {
                $generalPosting = $this->generalPosting->show((string) $execution->general_posting_id);
                $expected = $this->fingerprint(
                    $execution,
                    (string) ($execution->company_code ?? ''),
                    $this->normalizeMarking($execution->marking ?? 'UNMARKING'),
                    (string) ($execution->posting_template_id ?? ''),
                );
                $isFinal = in_array((string) $execution->status, ['POSTED', 'APPROVED'], true);
                $postingStatus = strtoupper((string) ($generalPosting['status'] ?? ''));
                if ($isFinal && $postingStatus === 'POSTED') {
                    // I05: final realization may point to the canonical AP settlement General Posting.
                    $stale = false;
                    $staleReason = null;
                } else {
                    $stale = ! hash_equals((string) ($execution->realization_fingerprint ?? ''), $expected);
                    if ($postingStatus !== 'DRAFT') {
                        $stale = true;
                        $staleReason = 'General Posting belum berada pada status yang sesuai fase realisasi.';
                    } elseif ((float) ($generalPosting['amount'] ?? 0) !== round((float) ($execution->actual_total_amount ?? 0), 2)) {
                        $stale = true;
                        $staleReason = 'Nominal General Posting berbeda dari actual realization.';
                    }
                }
            } catch (\Throwable $e) {
                $stale = true;
                $staleReason = $e->getMessage();
            }
        }

        $qtyReady = DB::table($d['items'])
            ->where('document_id', $id)
            ->where('executed_qty', '>', 0)
            ->exists();

        return [
            'realization_date' => $execution->realization_date ? (string) $execution->realization_date : null,
            'actual_subtotal' => round((float) ($execution->actual_subtotal ?? 0), 2),
            'actual_tax_amount' => round((float) ($execution->actual_tax_amount ?? 0), 2),
            'actual_total_amount' => round((float) ($execution->actual_total_amount ?? 0), 2),
            'company_code' => $execution->company_code ? (string) $execution->company_code : null,
            'marking' => (string) ($execution->marking ?: 'UNMARKING'),
            'posting_template_id' => $execution->posting_template_id ? (string) $execution->posting_template_id : null,
            'general_posting_id' => $execution->general_posting_id ? (string) $execution->general_posting_id : null,
            'realization_status' => (string) ($execution->realization_status ?? 'PENDING'),
            'posting_link' => $generalPosting,
            'posting_stale' => $stale,
            'posting_stale_reason' => $staleReason,
            'attachments' => $attachments,
            'attachment_summary' => $this->attachments->summary($attachmentType, $id, true),
            'qty_ready' => $qtyReady,
            'can_post_execution' => (string) $execution->status === 'DRAFT'
                && $qtyReady
                && (float) ($execution->actual_total_amount ?? 0) > 0
                && $execution->realization_date
                && $execution->posting_template_id
                && $execution->general_posting_id
                && ! $stale
                && count($attachments) > 0,
        ];
    }

    public function assertReadyForExecutionPost(string $kind, string $id): void
    {
        $d = $this->catalog->definition($kind);
        $execution = $this->execution($d, $id, true);

        if (! $execution->realization_date) {
            throw ValidationException::withMessages(['realization_date' => 'Tanggal realisasi wajib diisi sebelum POST.']);
        }
        if ((float) ($execution->actual_total_amount ?? 0) <= 0) {
            throw ValidationException::withMessages(['actual_total_amount' => 'Nominal aktual realisasi harus lebih besar dari 0.']);
        }
        if (! $execution->posting_template_id || ! $execution->general_posting_id) {
            throw ValidationException::withMessages([
                'posting_template_id' => 'Pilih Posting Template dan simpan realisasi sampai General Posting DRAFT terbentuk.',
            ]);
        }

        $attachmentType = $this->attachmentType($d['kind']);
        if (! $this->attachments->hasAny($attachmentType, $id)) {
            throw ValidationException::withMessages([
                'attachments' => 'Minimal satu lampiran bukti realisasi wajib diunggah sebelum POST.',
            ]);
        }

        $posting = DB::table('finance_general_postings')->where('id', $execution->general_posting_id)->first();
        if (! $posting || (string) $posting->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'general_posting_id' => 'General Posting realisasi wajib tetap berstatus DRAFT.',
            ]);
        }

        $expected = $this->fingerprint(
            $execution,
            (string) ($execution->company_code ?? ''),
            $this->normalizeMarking($execution->marking ?? 'UNMARKING'),
            (string) ($execution->posting_template_id ?? ''),
        );
        if (! hash_equals((string) ($execution->realization_fingerprint ?? ''), $expected)) {
            throw ValidationException::withMessages([
                'general_posting_id' => 'Data realisasi berubah. Simpan/Refresh General Posting DRAFT terlebih dahulu.',
            ]);
        }
    }

    public function markConfirmed(string $kind, string $id): void
    {
        $this->finalizeAfterApproval($kind, $id, null);
    }

    /** @return array<string,mixed> */
    public function finalizeAfterApproval(string $kind, string $id, ?User $actor = null): array
    {
        $d = $this->catalog->definition($kind);
        $execution = $this->execution($d, $id);
        if (! in_array((string) $execution->status, ['POSTED', 'APPROVED'], true)) {
            return ['status' => 'IGNORED', 'message' => 'Realization belum final approved.'];
        }

        $actorId = $actor?->id ?: ($execution->approved_by_user_id ?? $execution->posted_by_user_id ?? null);
        $canonical = $this->hasCanonicalApLifecycle($d, $execution);
        if ($canonical) {
            $settlement = Schema::hasTable('pur_order_ap_settlements')
                ? DB::table('pur_order_ap_settlements')->where('realization_kind', $d['kind'])->where('realization_id', $id)->first()
                : null;

            if ($settlement && ! empty($settlement->posting_event_key)) {
                $result = $this->financePosting->autoPostOutboxByEventKey((string) $settlement->posting_event_key, $actorId);
                $gp = $this->generalPostingForEvent((string) $settlement->posting_event_key);
                if ($gp && strtoupper((string) $gp->status) === 'POSTED') {
                    $this->replacePreviewWithFinal($d, $execution, (string) $gp->id, (string) $settlement->posting_event_key);
                    return ['status' => 'POSTED', 'general_posting_id' => (string) $gp->id, 'journal_no' => $result['journal_no'] ?? null, 'idempotent' => (bool) ($result['idempotent'] ?? false)];
                }

                DB::table($d['table'])->where('id', $id)->update([
                    'realization_status' => strtoupper((string) ($result['status'] ?? 'NEEDS_MAPPING')),
                    'updated_at' => now(),
                ]);
                return ['status' => strtoupper((string) ($result['status'] ?? 'NEEDS_MAPPING')), 'message' => $result['message'] ?? 'Finance posting settlement belum berhasil.'];
            }

            DB::table($d['table'])->where('id', $id)->update(['realization_status' => 'PENDING_FINANCE', 'updated_at' => now()]);
            return ['status' => 'PENDING_FINANCE', 'message' => 'Canonical AP settlement belum tersedia.'];
        }

        return $this->promoteLegacyPreview($d, $execution, $actorId);
    }

    private function hasCanonicalApLifecycle(array $d, object $execution): bool
    {
        if (! Schema::hasTable('pur_order_ap_lifecycles')) return false;
        return DB::table('pur_order_ap_lifecycles')
            ->where('order_kind', $d['order_kind'])
            ->where('order_id', (string) $execution->order_id)
            ->exists();
    }

    private function generalPostingForEvent(string $eventKey): ?object
    {
        $outbox = DB::table('pur_finance_posting_outbox')->where('event_key', $eventKey)->first();
        if (! $outbox) return null;
        if (Schema::hasColumn('pur_finance_posting_outbox', 'general_posting_id') && ! empty($outbox->general_posting_id)) {
            return DB::table('finance_general_postings')->where('id', $outbox->general_posting_id)->first();
        }

        $posting = DB::table('finance_purchasing_postings')->where('event_key', $eventKey)->first();
        if (! $posting || (string) $posting->status !== 'POSTED') return null;
        $journal = DB::table('finance_purchasing_posting_journals')
            ->where('purchasing_posting_id', $posting->id)
            ->where('posting_version', $posting->posting_version)
            ->first();
        return $journal ? $this->generalPosting->generalPostingByJournal((string) $journal->journal_entry_id) : null;
    }

    private function replacePreviewWithFinal(array $d, object $execution, string $finalGeneralPostingId, string $eventKey): void
    {
        $old = $execution->general_posting_id ? (string) $execution->general_posting_id : null;
        if ($old && $old !== $finalGeneralPostingId) {
            $posting = DB::table('finance_general_postings')->where('id', $old)->first();
            if ($posting && (string) $posting->status === 'DRAFT' && (string) $posting->source_code === self::SOURCE_CODE) {
                try { $this->generalPosting->destroyDraft($old); } catch (\Throwable) { /* audit-safe: leave preview if protected */ }
            }
        }

        DB::table($d['table'])->where('id', $execution->id)->update([
            'general_posting_id' => $finalGeneralPostingId,
            'realization_status' => 'POSTED',
            'realization_fingerprint' => hash('sha256', 'ERP-V5-I05|'.$eventKey.'|'.$finalGeneralPostingId),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function promoteLegacyPreview(array $d, object $execution, ?string $actorId): array
    {
        $draftId = $execution->general_posting_id ? (string) $execution->general_posting_id : null;
        if (! $draftId) {
            DB::table($d['table'])->where('id', $execution->id)->update(['realization_status' => 'PENDING_FINANCE', 'updated_at' => now()]);
            return ['status' => 'PENDING_FINANCE', 'message' => 'Legacy realization belum memiliki General Posting preview.'];
        }

        $draft = DB::table('finance_general_postings')->where('id', $draftId)->first();
        if (! $draft) return ['status' => 'PENDING_FINANCE', 'message' => 'General Posting preview tidak ditemukan.'];
        if ((string) $draft->status === 'POSTED') {
            DB::table($d['table'])->where('id', $execution->id)->update(['realization_status' => 'POSTED', 'updated_at' => now()]);
            return ['status' => 'POSTED', 'general_posting_id' => $draftId, 'idempotent' => true];
        }

        $preview = $this->generalPosting->previewOne($draftId);
        $lines = array_map(fn (array $line): array => [
            'account_id' => (string) $line['account_id'],
            'debit' => round((float) $line['debit'], 2),
            'credit' => round((float) $line['credit'], 2),
            'description' => (string) ($line['description'] ?? $draft->description),
        ], $preview['lines'] ?? []);

        $eventKey = 'PUR-REAL-FINAL:'.$d['kind'].':'.(string) $execution->id;
        $posted = $this->generalPosting->stageSystem([
            'source_key' => $eventKey,
            'source_code' => 'PUR_REALIZATION_AUTO',
            'source_module' => 'PURCHASING',
            'source_identity' => (string) $execution->id,
            'reference_no' => (string) $execution->{$d['number']},
            'company_code' => (string) $execution->company_code,
            'outlet_id' => $execution->outlet_id ? (string) $execution->outlet_id : null,
            'marking' => $this->normalizeMarking($execution->marking ?? 'UNMARKING'),
            'business_date' => (string) ($execution->realization_date ?: $execution->document_date),
            'journal_date' => (string) ($execution->realization_date ?: $execution->document_date),
            'description' => 'Final realization '.$d['label'].' '.(string) $execution->{$d['number']},
            'payable' => round((float) ($execution->actual_total_amount ?? 0), 2),
            'metadata' => [
                'erp_v5_i05' => true,
                'legacy_realization_fallback' => true,
                'requested_template_id' => $execution->posting_template_id ?: null,
                'preview_general_posting_id' => $draftId,
                'execution_kind' => $d['kind'],
                'execution_id' => (string) $execution->id,
            ],
        ], $lines, $actorId, true);

        $this->replacePreviewWithFinal($d, $execution, (string) $posted['general_posting_id'], $eventKey);
        return ['status' => 'POSTED', 'general_posting_id' => (string) $posted['general_posting_id'], 'journal_no' => $posted['journal_no'] ?? null, 'idempotent' => (bool) ($posted['idempotent'] ?? false)];
    }

    private function detachUnusedDraft(array $d, object $execution): void
    {
        if (! $execution->general_posting_id) {
            return;
        }

        $posting = DB::table('finance_general_postings')->where('id', $execution->general_posting_id)->first();
        if ($posting && (string) $posting->status === 'DRAFT' && (string) $posting->source_code === self::SOURCE_CODE) {
            try {
                $this->generalPosting->destroyDraft((string) $posting->id);
            } catch (\Throwable) {
                // Keep history if Finance service refuses deletion.
            }
        }

        DB::table($d['table'])->where('id', $execution->id)->update([
            'general_posting_id' => null,
            'realization_fingerprint' => null,
            'updated_at' => now(),
        ]);
    }

    private function validateTemplate(
        string $templateId,
        string $companyCode,
        ?string $outletId,
        string $marking,
    ): object {
        $template = DB::table('finance_posting_templates')
            ->where('id', $templateId)
            ->where('source_type', 'GENERAL')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $template) {
            throw ValidationException::withMessages(['posting_template_id' => 'Posting Template GENERAL tidak ditemukan/aktif.']);
        }
        if ($template->company_code && strtoupper((string) $template->company_code) !== $companyCode) {
            throw ValidationException::withMessages(['posting_template_id' => 'Posting Template tidak sesuai PT realisasi.']);
        }
        if ($template->outlet_id && (string) $template->outlet_id !== (string) $outletId) {
            throw ValidationException::withMessages(['posting_template_id' => 'Posting Template tidak sesuai outlet realisasi.']);
        }
        if ($template->marking && strtoupper((string) $template->marking) !== $marking) {
            throw ValidationException::withMessages(['posting_template_id' => 'Posting Template tidak sesuai marking realisasi.']);
        }

        return $template;
    }

    private function resolveCompany(?string $outletId, string $date, mixed $requested): ?string
    {
        if ($outletId) {
            $mapped = DB::table('finance_outlet_company_mappings')
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
                })
                ->where(function ($query) use ($date): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
                })
                ->value('company_code');

            if (! $mapped) {
                throw ValidationException::withMessages([
                    'company_code' => 'Outlet belum mempunyai mapping PT aktif di Finance.',
                ]);
            }

            return strtoupper((string) $mapped);
        }

        $company = strtoupper(trim((string) ($requested ?? '')));
        if ($company === '') return null;

        $active = Schema::hasTable('finance_companies')
            ? DB::table('finance_companies')->where('is_active', true)->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all()
            : ['BKJB', 'MDMF', 'APB'];
        if (! in_array($company, $active, true)) {
            throw ValidationException::withMessages(['company_code' => 'PT tidak aktif/tidak dikenal pada master Finance.']);
        }

        return $company;
    }

    private function normalizeMarking(mixed $value): string
    {
        $marking = strtoupper(trim((string) ($value ?: 'UNMARKING')));
        if (! in_array($marking, ['MARKING', 'UNMARKING'], true)) {
            throw ValidationException::withMessages(['marking' => 'Marking harus MARKING atau UNMARKING.']);
        }

        return $marking;
    }

    private function fingerprint(object $execution, string $company, string $marking, string $templateId): string
    {
        return hash('sha256', json_encode([
            'execution_id' => (string) $execution->id,
            'realization_date' => (string) ($execution->realization_date ?? ''),
            'actual_subtotal' => round((float) ($execution->actual_subtotal ?? 0), 2),
            'actual_tax_amount' => round((float) ($execution->actual_tax_amount ?? 0), 2),
            'actual_total_amount' => round((float) ($execution->actual_total_amount ?? 0), 2),
            'company_code' => strtoupper($company),
            'outlet_id' => $execution->outlet_id ? (string) $execution->outlet_id : null,
            'marking' => $marking,
            'posting_template_id' => $templateId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function execution(array $d, string $id, bool $lock = false): object
    {
        $query = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        if (! $row) {
            abort(404, 'Dokumen eksekusi Purchasing tidak ditemukan.');
        }

        return $row;
    }

    private function attachmentType(string $kind): string
    {
        return match ($kind) {
            'SERVICE_ENTRY_SHEET' => PurchasingDocumentAttachmentService::SERVICE_ENTRY_SHEET,
            'GOODS_RECEIPT' => PurchasingDocumentAttachmentService::GOODS_RECEIPT,
            'SERVICE_ACCEPTANCE' => PurchasingDocumentAttachmentService::SERVICE_ACCEPTANCE,
            'REIMBURSE_PAYMENT' => PurchasingDocumentAttachmentService::REIMBURSE_PAYMENT,
            default => throw new InvalidArgumentException('Jenis execution attachment tidak dikenali.'),
        };
    }
}
