<?php

namespace App\Services\Warehouse\FinanceV4;

use App\Services\Warehouse\FinanceV8\WarehouseFinanceCanonicalBridgeV8Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseGeneralPostingEngine
{
    public function __construct(private readonly WarehouseFinanceCanonicalBridgeV8Service $canonicalBridge) {}

    public function createManualDraft(
        string $warehouseId,
        array $payload,
        string $userId,
    ): array {
        $lines = $this->normalizeManualLines($payload['lines'] ?? []);

        return $this->createDraft(
            warehouseId: $warehouseId,
            sourceType: 'MANUAL',
            sourceKey: trim((string) ($payload['source_key'] ?? '')) ?: 'MANUAL:'.Str::uuid(),
            sourceId: null,
            referenceNo: $payload['reference_no'] ?? null,
            businessDate: (string) $payload['business_date'],
            journalDate: (string) ($payload['journal_date'] ?? $payload['business_date']),
            description: $payload['description'] ?? null,
            templateId: null,
            currencyCode: strtoupper((string) ($payload['currency_code'] ?? 'IDR')),
            lines: $lines,
            metadata: ['origin' => 'manual', 'notes' => $payload['notes'] ?? null],
            userId: $userId,
        );
    }

    public function createFromTemplate(
        string $warehouseId,
        string $templateCode,
        array $payload,
        string $userId,
    ): array {
        $template = DB::table('wh_v4_finance_posting_templates')
            ->where('code', $templateCode)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            throw ValidationException::withMessages([
                'template_code' => ['Template Posting Warehouse aktif tidak ditemukan.'],
            ]);
        }

        $templateLines = DB::table('wh_v4_finance_posting_template_lines as l')
            ->join('wh_v4_finance_coa as a', 'a.id', '=', 'l.account_id')
            ->where('l.template_id', $template->id)
            ->where('a.is_active', true)
            ->where('a.is_postable', true)
            ->orderBy('l.sort_order')
            ->get([
                'l.account_id',
                'l.side',
                'l.amount_key',
                'l.multiplier',
                'l.memo_template',
                'a.code as account_code',
                'a.name as account_name',
                'a.account_type',
                'a.normal_balance',
            ]);

        if ($templateLines->isEmpty()) {
            throw ValidationException::withMessages([
                'template_code' => ['Template belum memiliki line debit/credit aktif.'],
            ]);
        }

        $amounts = collect((array) ($payload['amounts'] ?? []))
            ->mapWithKeys(fn ($value, $key) => [(string) $key => round((float) $value, 2)])
            ->all();

        $variables = [
            'reference_no' => (string) ($payload['reference_no'] ?? ''),
            'source_id' => (string) ($payload['source_id'] ?? ''),
            'warehouse_id' => $warehouseId,
        ];

        $lines = [];
        foreach ($templateLines as $row) {
            $key = (string) $row->amount_key;
            if (! array_key_exists($key, $amounts)) {
                throw ValidationException::withMessages([
                    "amounts.{$key}" => ["Amount key '{$key}' wajib disediakan oleh source transaksi."],
                ]);
            }

            $amount = round(abs((float) $amounts[$key] * (float) $row->multiplier), 2);
            if ($amount <= 0) {
                continue;
            }

            $side = strtoupper((string) $row->side);
            if (! in_array($side, ['DEBIT', 'CREDIT'], true)) {
                throw ValidationException::withMessages([
                    'template_code' => ["Template {$templateCode} memiliki side tidak valid."],
                ]);
            }

            $lines[] = [
                'account_id' => (string) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'account_type' => (string) $row->account_type,
                'normal_balance' => (string) $row->normal_balance,
                'description' => $this->render((string) ($row->memo_template ?? ''), $variables),
                'debit' => $side === 'DEBIT' ? $amount : 0,
                'credit' => $side === 'CREDIT' ? $amount : 0,
                'metadata' => ['amount_key' => $key, 'multiplier' => (float) $row->multiplier],
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'amounts' => ['Seluruh nilai template menghasilkan jurnal nol.'],
            ]);
        }

        $sourceType = strtoupper(trim((string) ($payload['source_type'] ?? $template->source_type)));
        $sourceId = trim((string) ($payload['source_id'] ?? '')) ?: null;
        $sourceKey = trim((string) ($payload['source_key'] ?? ''));
        if ($sourceKey === '') {
            if (! $sourceId) {
                throw ValidationException::withMessages([
                    'source_key' => ['source_key atau source_id wajib untuk posting berbasis template.'],
                ]);
            }
            $sourceKey = $sourceType.':'.$sourceId.':'.$templateCode;
        }

        return $this->createDraft(
            warehouseId: $warehouseId,
            sourceType: $sourceType,
            sourceKey: $sourceKey,
            sourceId: $sourceId,
            referenceNo: $payload['reference_no'] ?? null,
            businessDate: (string) $payload['business_date'],
            journalDate: (string) ($payload['journal_date'] ?? $payload['business_date']),
            description: $payload['description'] ?? $template->name,
            templateId: (string) $template->id,
            currencyCode: strtoupper((string) ($payload['currency_code'] ?? 'IDR')),
            lines: $lines,
            metadata: array_merge((array) ($payload['metadata'] ?? []), [
                'template_code' => $templateCode,
                'amounts' => $amounts,
            ]),
            userId: $userId,
        );
    }

    public function post(string $postingId, array $allowedWarehouseIds, string $userId): array
    {
        $result = DB::transaction(function () use ($postingId, $allowedWarehouseIds, $userId): array {
            $posting = DB::table('wh_v4_finance_general_postings')
                ->where('id', $postingId)
                ->whereIn('warehouse_id', $allowedWarehouseIds)
                ->lockForUpdate()
                ->first();

            if (! $posting) {
                abort(404, 'General Posting Warehouse tidak ditemukan.');
            }

            if ($posting->status === 'POSTED') {
                return $this->detail($postingId, $allowedWarehouseIds);
            }

            if ($posting->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya General Posting DRAFT yang dapat diposting.'],
                ]);
            }

            $this->assertBalanced((float) $posting->total_debit, (float) $posting->total_credit);

            DB::table('wh_v4_finance_general_postings')->where('id', $postingId)->update([
                'status' => 'POSTED',
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            $this->event($postingId, 'posted', [
                'posting_no' => $posting->posting_no,
                'total_debit' => (float) $posting->total_debit,
                'total_credit' => (float) $posting->total_credit,
            ], $userId);

            return $this->detail($postingId, $allowedWarehouseIds);
        }, 5);

        // Iteration 08: projection to canonical Finance GL is non-blocking.
        // Missing Warehouse→PT mapping is recorded as PENDING_SCOPE, never guessed.
        $this->canonicalBridge->syncPosting($postingId, $userId, false);
        return $result;
    }

    public function reverse(string $postingId, array $allowedWarehouseIds, string $reason, string $userId): array
    {
        $result = DB::transaction(function () use ($postingId, $allowedWarehouseIds, $reason, $userId): array {
            $original = DB::table('wh_v4_finance_general_postings')
                ->where('id', $postingId)
                ->whereIn('warehouse_id', $allowedWarehouseIds)
                ->lockForUpdate()
                ->first();

            if (! $original) {
                abort(404, 'General Posting Warehouse tidak ditemukan.');
            }

            if ($original->reversal_posting_id) {
                return [
                    'original' => $this->detail($postingId, $allowedWarehouseIds),
                    'reversal' => $this->detail((string) $original->reversal_posting_id, $allowedWarehouseIds),
                ];
            }

            if ($original->status !== 'POSTED') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya General Posting POSTED yang dapat direverse.'],
                ]);
            }

            $sourceLines = DB::table('wh_v4_finance_general_posting_lines')
                ->where('general_posting_id', $postingId)
                ->orderBy('line_no')
                ->get();

            $reversalId = (string) Str::ulid();
            $postingNo = $this->nextPostingNo('WRV');
            $now = now();

            DB::table('wh_v4_finance_general_postings')->insert([
                'id' => $reversalId,
                'posting_no' => $postingNo,
                'source_key' => 'REVERSAL:'.$postingId,
                'source_type' => 'REVERSAL',
                'source_id' => $postingId,
                'reference_no' => $original->posting_no,
                'warehouse_id' => $original->warehouse_id,
                'business_date' => $now->toDateString(),
                'journal_date' => $now->toDateString(),
                'template_id' => null,
                'currency_code' => $original->currency_code,
                'description' => 'Reversal '.$original->posting_no.' — '.$reason,
                'status' => 'POSTED',
                'total_debit' => $original->total_credit,
                'total_credit' => $original->total_debit,
                'source_fingerprint' => hash('sha256', 'REVERSAL:'.$postingId.':'.$reason),
                'metadata' => json_encode(['reason' => $reason, 'reversal_of' => $postingId]),
                'reversal_of_posting_id' => $postingId,
                'reversal_posting_id' => null,
                'posted_at' => $now,
                'posted_by_user_id' => $userId,
                'reversed_at' => null,
                'reversed_by_user_id' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($sourceLines as $index => $line) {
                DB::table('wh_v4_finance_general_posting_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'general_posting_id' => $reversalId,
                    'line_no' => $index + 1,
                    'account_id' => $line->account_id,
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'account_type' => $line->account_type,
                    'normal_balance' => $line->normal_balance,
                    'description' => 'REVERSAL — '.((string) ($line->description ?? '')),
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'metadata' => json_encode(['reversal_of_line_id' => (string) $line->id]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('wh_v4_finance_general_postings')->where('id', $postingId)->update([
                'status' => 'REVERSED',
                'reversal_posting_id' => $reversalId,
                'reversed_at' => $now,
                'reversed_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => $now,
            ]);

            $this->event($postingId, 'reversed', [
                'reversal_posting_id' => $reversalId,
                'reversal_posting_no' => $postingNo,
                'reason' => $reason,
            ], $userId);
            $this->event($reversalId, 'posted', [
                'reversal_of_posting_id' => $postingId,
                'reason' => $reason,
            ], $userId);

            return [
                'original' => $this->detail($postingId, $allowedWarehouseIds),
                'reversal' => $this->detail($reversalId, $allowedWarehouseIds),
            ];
        }, 5);

        $this->canonicalBridge->syncPosting($postingId, $userId, false);
        $reversalId=(string)($result['reversal']['id'] ?? '');
        if ($reversalId !== '') $this->canonicalBridge->syncPosting($reversalId, $userId, false);
        return $result;
    }

    public function detail(string $postingId, array $allowedWarehouseIds): array
    {
        $posting = DB::table('wh_v4_finance_general_postings as p')
            ->leftJoin('outlets as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('wh_v4_finance_posting_templates as t', 't.id', '=', 'p.template_id')
            ->where('p.id', $postingId)
            ->whereIn('p.warehouse_id', $allowedWarehouseIds)
            ->select('p.*', 'w.code as warehouse_code', 'w.name as warehouse_name', 't.code as template_code', 't.name as template_name')
            ->first();

        if (! $posting) {
            abort(404, 'General Posting Warehouse tidak ditemukan.');
        }

        $lines = DB::table('wh_v4_finance_general_posting_lines')
            ->where('general_posting_id', $postingId)
            ->orderBy('line_no')
            ->get()
            ->map(fn ($line) => [
                'id' => (string) $line->id,
                'line_no' => (int) $line->line_no,
                'account_id' => (string) $line->account_id,
                'account_code' => (string) $line->account_code,
                'account_name' => (string) $line->account_name,
                'account_type' => (string) $line->account_type,
                'normal_balance' => (string) $line->normal_balance,
                'description' => $line->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'metadata' => $this->json($line->metadata),
            ])->values()->all();

        return [
            'id' => (string) $posting->id,
            'posting_no' => (string) $posting->posting_no,
            'source_key' => $posting->source_key,
            'source_type' => (string) $posting->source_type,
            'source_id' => $posting->source_id,
            'reference_no' => $posting->reference_no,
            'warehouse_id' => (string) $posting->warehouse_id,
            'warehouse_code' => $posting->warehouse_code,
            'warehouse_name' => $posting->warehouse_name,
            'business_date' => (string) $posting->business_date,
            'journal_date' => (string) $posting->journal_date,
            'template_id' => $posting->template_id,
            'template_code' => $posting->template_code,
            'template_name' => $posting->template_name,
            'currency_code' => (string) $posting->currency_code,
            'description' => $posting->description,
            'status' => (string) $posting->status,
            'total_debit' => (float) $posting->total_debit,
            'total_credit' => (float) $posting->total_credit,
            'metadata' => $this->json($posting->metadata),
            'reversal_of_posting_id' => $posting->reversal_of_posting_id,
            'reversal_posting_id' => $posting->reversal_posting_id,
            'posted_at' => $posting->posted_at,
            'reversed_at' => $posting->reversed_at,
            'created_at' => $posting->created_at,
            'lines' => $lines,
        ];
    }

    private function createDraft(
        string $warehouseId,
        string $sourceType,
        string $sourceKey,
        ?string $sourceId,
        ?string $referenceNo,
        string $businessDate,
        string $journalDate,
        ?string $description,
        ?string $templateId,
        string $currencyCode,
        array $lines,
        array $metadata,
        string $userId,
    ): array {
        return DB::transaction(function () use (
            $warehouseId, $sourceType, $sourceKey, $sourceId, $referenceNo,
            $businessDate, $journalDate, $description, $templateId, $currencyCode,
            $lines, $metadata, $userId,
        ): array {
            $warehouse = DB::table('outlets')
                ->where('id', $warehouseId)
                ->where('is_active', true)
                ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['', 'warehouse'])
                ->first(['id']);

            if (! $warehouse) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Warehouse aktif tidak ditemukan.'],
                ]);
            }

            [$totalDebit, $totalCredit] = $this->totals($lines);
            $this->assertBalanced($totalDebit, $totalCredit);

            $fingerprint = hash('sha256', json_encode([
                'warehouse_id' => $warehouseId,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'source_id' => $sourceId,
                'reference_no' => $referenceNo,
                'business_date' => $businessDate,
                'journal_date' => $journalDate,
                'template_id' => $templateId,
                'currency_code' => $currencyCode,
                'lines' => array_map(fn ($line) => [
                    $line['account_id'],
                    round((float) $line['debit'], 2),
                    round((float) $line['credit'], 2),
                ], $lines),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $existing = DB::table('wh_v4_finance_general_postings')
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((string) $existing->source_fingerprint !== $fingerprint) {
                    throw ValidationException::withMessages([
                        'source_key' => ['source_key sudah pernah digunakan dengan payload debit/credit berbeda.'],
                    ]);
                }

                return $this->detail((string) $existing->id, [$warehouseId]);
            }

            $postingId = (string) Str::ulid();
            $postingNo = $this->nextPostingNo('WGP');
            $now = now();

            DB::table('wh_v4_finance_general_postings')->insert([
                'id' => $postingId,
                'posting_no' => $postingNo,
                'source_key' => $sourceKey,
                'source_type' => strtoupper($sourceType),
                'source_id' => $sourceId,
                'reference_no' => $referenceNo,
                'warehouse_id' => $warehouseId,
                'business_date' => $businessDate,
                'journal_date' => $journalDate,
                'template_id' => $templateId,
                'currency_code' => $currencyCode ?: 'IDR',
                'description' => $description,
                'status' => 'DRAFT',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'source_fingerprint' => $fingerprint,
                'metadata' => json_encode($metadata),
                'reversal_of_posting_id' => null,
                'reversal_posting_id' => null,
                'posted_at' => null,
                'posted_by_user_id' => null,
                'reversed_at' => null,
                'reversed_by_user_id' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $index => $line) {
                DB::table('wh_v4_finance_general_posting_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'general_posting_id' => $postingId,
                    'line_no' => $index + 1,
                    'account_id' => $line['account_id'],
                    'account_code' => $line['account_code'],
                    'account_name' => $line['account_name'],
                    'account_type' => $line['account_type'],
                    'normal_balance' => $line['normal_balance'],
                    'description' => $line['description'] ?? null,
                    'debit' => round((float) $line['debit'], 2),
                    'credit' => round((float) $line['credit'], 2),
                    'metadata' => json_encode($line['metadata'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->event($postingId, 'created', [
                'posting_no' => $postingNo,
                'source_type' => strtoupper($sourceType),
                'source_key' => $sourceKey,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ], $userId);

            return $this->detail($postingId, [$warehouseId]);
        }, 5);
    }

    private function normalizeManualLines(array $payloadLines): array
    {
        if (count($payloadLines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['Minimal dua line debit/credit diperlukan.'],
            ]);
        }

        $accountIds = collect($payloadLines)
            ->pluck('account_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $accounts = DB::table('wh_v4_finance_coa')
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->get()
            ->keyBy(fn ($row) => (string) $row->id);

        $lines = [];
        foreach ($payloadLines as $index => $line) {
            $accountId = (string) ($line['account_id'] ?? '');
            $account = $accounts->get($accountId);
            if (! $account) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['COA aktif dan postable tidak ditemukan.'],
                ]);
            }

            $debit = round(max(0, (float) ($line['debit'] ?? 0)), 2);
            $credit = round(max(0, (float) ($line['credit'] ?? 0)), 2);

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => ['Setiap line harus tepat salah satu: debit > 0 atau credit > 0.'],
                ]);
            }

            $lines[] = [
                'account_id' => (string) $account->id,
                'account_code' => (string) $account->code,
                'account_name' => (string) $account->name,
                'account_type' => (string) $account->account_type,
                'normal_balance' => (string) $account->normal_balance,
                'description' => $line['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'metadata' => [],
            ];
        }

        return $lines;
    }

    private function totals(array $lines): array
    {
        $debit = round(array_sum(array_map(fn ($line) => (float) $line['debit'], $lines)), 2);
        $credit = round(array_sum(array_map(fn ($line) => (float) $line['credit'], $lines)), 2);

        return [$debit, $credit];
    }

    private function assertBalanced(float $debit, float $credit): void
    {
        if ($debit <= 0 || $credit <= 0 || abs($debit - $credit) > 0.01) {
            throw ValidationException::withMessages([
                'journal' => ["General Posting tidak balance. Debit {$debit}, Credit {$credit}."],
            ]);
        }
    }

    private function nextPostingNo(string $prefix): string
    {
        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(7));
        } while (DB::table('wh_v4_finance_general_postings')->where('posting_no', $number)->exists());

        return $number;
    }

    private function event(string $postingId, string $eventType, array $payload, ?string $userId): void
    {
        DB::table('wh_v4_finance_general_posting_events')->insert([
            'id' => (string) Str::ulid(),
            'general_posting_id' => $postingId,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'actor_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function render(string $template, array $variables): string
    {
        $value = $template;
        foreach ($variables as $key => $replacement) {
            $value = str_replace('{{'.$key.'}}', (string) $replacement, $value);
        }

        return trim($value);
    }

    private function json($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
