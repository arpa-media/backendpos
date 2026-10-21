<?php

namespace App\Services\Cogs\V13;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Immutable per-sale consumption snapshot and two-person approval.
 * NO writes to POS sales, stock movement, COGS legacy runs or Finance GL.
 */
final class FinalCogsI27Service
{
    public function __construct(private readonly VarianceAllocationI26Service $allocator) {}

    private function fresh(string $varianceId, string $outletId): array
    {
        // Internal I26 call fetches the entire eligible result; HTTP paging limit remains 100.
        $report = $this->allocator->report($varianceId, $outletId, 1, 12001);
        $snapshot = FinalCogsI27Math::snapshot($report);
        return [$report, $snapshot];
    }

    private function signature(string $runId, string $fingerprint, string $snapshotHash, string $linesHash): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new \RuntimeException('APP_KEY tidak tersedia untuk audit snapshot.');
        }
        return hash_hmac('sha256', "v13-i27|{$runId}|{$fingerprint}|{$snapshotHash}|{$linesHash}", $secret);
    }

    private function assertSignature(object $run): void
    {
        if (!hash_equals((string) $run->snapshot_signature, $this->signature(
            (string) $run->id, (string) $run->source_fingerprint, (string) $run->snapshot_hash, (string) $run->lines_hash,
        ))) {
            throw ValidationException::withMessages(['run_id' => ['Tanda tangan snapshot tidak valid. Approval diblokir.']]);
        }
    }

    private function event(string $id, string $actor, string $action, ?string $from, string $to, string $hash, ?string $notes): void
    {
        DB::table('cogs_v13_final_events')->insert([
            'id' => (string) Str::ulid(), 'run_id' => $id, 'actor_id' => $actor,
            'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'snapshot_hash' => $hash, 'notes' => $notes, 'created_at' => now(),
        ]);
    }

    private function lockVariance(string $varianceId, string $outletId): object
    {
        $variance = DB::table('cogs_stock_variances')
            ->where('id', $varianceId)->where('outlet_id', $outletId)->lockForUpdate()->first();
        if (!$variance) abort(404, 'Variance tidak ditemukan pada outlet terpilih.');
        if ($variance->status !== 'submitted') {
            throw ValidationException::withMessages(['variance_id' => ['Hanya variance submitted yang dapat difinalisasi.']]);
        }
        return $variance;
    }

    public function prepare(string $outletId, string $varianceId, string $expectedFingerprint, string $actor): array
    {
        return DB::transaction(function () use ($outletId, $varianceId, $expectedFingerprint, $actor): array {
            $this->lockVariance($varianceId, $outletId);
            $latest = DB::table('cogs_v13_final_runs')->where('variance_id', $varianceId)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($latest && $latest->status === 'approved') {
                throw ValidationException::withMessages(['variance_id' => ['Snapshot sudah approved dan immutable. Gunakan proses koreksi terpisah.']]);
            }
            if ($latest && $latest->status === 'staged') {
                throw ValidationException::withMessages(['variance_id' => ['Proposal masih menunggu approval/reject. Selesaikan terlebih dahulu.']]);
            }
            [$report, $snapshot] = $this->fresh($varianceId, $outletId);
            if (!hash_equals((string) $report['fingerprint'], $expectedFingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => ['Hasil alokasi selisih stok berubah. Muat ulang alokasi sebelum membuat proposal.']]);
            }
            $totals = $snapshot['totals'];
            $id = (string) Str::ulid();
            $now = now();
            $lines = $snapshot['lines'];
            $linesHash = str_repeat('0', 64); // replaced with hash of canonical, persisted DB decimals after insert
            DB::table('cogs_v13_final_runs')->insert([
                'id' => $id, 'outlet_id' => $outletId, 'variance_id' => $varianceId,
                'revision' => ((int) ($latest->revision ?? 0)) + 1, 'status' => 'staged',
                'source_fingerprint' => (string) $report['fingerprint'],
                'snapshot_hash' => $snapshot['snapshot_hash'], 'lines_hash' => $linesHash,
                'snapshot_signature' => str_repeat('0', 64), // finalized only after all item rows persist
                'period_from' => $report['period']['from_exclusive'],
                'period_to' => $report['period']['to_inclusive'],
                'sale_item_count' => $totals['sale_items'], 'ingredient_line_count' => $totals['ingredients'],
                'historical_hpp' => $totals['historical_hpp'],
                'variance_adjustment' => $totals['adjustment'], 'final_hpp' => $totals['final_hpp'],
                'unallocated_variance' => $totals['unallocated'],
                'prepared_by' => $actor, 'prepared_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $batch = [];
            foreach ($lines as $row) {
                $batch[] = [
                    'id' => (string) Str::ulid(), 'run_id' => $id,
                    'consumption_id' => $row['consumption_id'],
                    'sale_id' => $row['sale_id'] ?: null, 'sale_item_id' => $row['sale_item_id'] ?: null,
                    'business_date' => $row['business_date'], 'product_name' => $row['product'],
                    'variant_name' => $row['variant'], 'quantity_sold' => $row['quantity_sold'],
                    'historical_hpp' => $row['historical_hpp'], 'allocation_cost' => $row['allocation_cost'],
                    'final_hpp' => $row['final_hpp'],
                    'ingredient_snapshot' => json_encode($row['ingredients'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($batch) >= 150) { DB::table('cogs_v13_final_lines')->insert($batch); $batch = []; }
            }
            if ($batch) DB::table('cogs_v13_final_lines')->insert($batch);
            // MySQL decimal(24,8) normalizes numeric strings; hash persisted representations.
            $canonicalLines = $this->sourceLines($id);
            $canonicalHash = hash('sha256', json_encode($canonicalLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            DB::table('cogs_v13_final_runs')->where('id', $id)->update([
                'lines_hash' => $canonicalHash,
                'snapshot_signature' => $this->signature($id, (string) $report['fingerprint'], $snapshot['snapshot_hash'], $canonicalHash),
            ]);
            $this->event($id, $actor, 'prepare', null, 'staged', $snapshot['snapshot_hash'], null);
            return $this->details($id, $outletId, 1, 20);
        }, 3);
    }

    public function approve(string $id, string $outletId, string $actor, string $fingerprint, string $notes): array
    {
        return DB::transaction(function () use ($id, $outletId, $actor, $fingerprint, $notes): array {
            $lookup = DB::table('cogs_v13_final_runs')->where('id', $id)->where('outlet_id', $outletId)->first();
            if (!$lookup) abort(404, 'Proposal tidak ditemukan.');
            $this->lockVariance((string) $lookup->variance_id, $outletId);
            $run = DB::table('cogs_v13_final_runs')->where('id', $id)->where('outlet_id', $outletId)->lockForUpdate()->first();
            $this->assertSignature($run);
            if ($run->status !== 'staged') {
                throw ValidationException::withMessages(['run_id' => ['Proposal tidak lagi dalam status staged.']]);
            }
            if ((string) $run->prepared_by === $actor) {
                throw ValidationException::withMessages(['approver' => ['Pembuat proposal tidak boleh meng-approve proposal sendiri.']]);
            }
            if (!hash_equals((string) $run->source_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => ['Fingerprint sumber berbeda dari proposal.']]);
            }
            [$report, $current] = $this->fresh((string) $run->variance_id, $outletId);
            if (!hash_equals((string) $run->source_fingerprint, (string) $report['fingerprint'])
                || !hash_equals((string) $run->snapshot_hash, $current['snapshot_hash'])) {
                throw ValidationException::withMessages(['run_id' => ['Data ledger berubah setelah proposal dibuat. Tolak proposal dan buat ulang dari alokasi selisih stok terkini.']]);
            }
            $this->assertStoredLines($run);
            DB::table('cogs_v13_final_runs')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(),
                'approval_notes' => $notes, 'updated_at' => now(),
            ]);
            $this->event($id, $actor, 'approve', 'staged', 'approved', (string) $run->snapshot_hash, $notes);
            return $this->details($id, $outletId, 1, 20);
        }, 3);
    }

    public function reject(string $id, string $outletId, string $actor, string $reason): array
    {
        return DB::transaction(function () use ($id, $outletId, $actor, $reason): array {
            $lookup = DB::table('cogs_v13_final_runs')->where('id', $id)->where('outlet_id', $outletId)->first();
            if (!$lookup) abort(404, 'Proposal tidak ditemukan.');
            $this->lockVariance((string) $lookup->variance_id, $outletId);
            $run = DB::table('cogs_v13_final_runs')->where('id', $id)->lockForUpdate()->first();
            $this->assertSignature($run);
            if ($run->status !== 'staged') {
                throw ValidationException::withMessages(['run_id' => ['Hanya proposal staged yang dapat ditolak.']]);
            }
            DB::table('cogs_v13_final_runs')->where('id', $id)->update([
                'status' => 'rejected', 'rejected_by' => $actor, 'rejected_at' => now(),
                'reject_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->event($id, $actor, 'reject', 'staged', 'rejected', (string) $run->snapshot_hash, $reason);
            return $this->details($id, $outletId, 1, 20);
        }, 3);
    }

    private function sourceLines(string $runId): array
    {
        return DB::table('cogs_v13_final_lines')->where('run_id', $runId)->orderBy('consumption_id')->get()->map(static function ($row): array {
            return [
                'consumption_id' => (string) $row->consumption_id,
                'sale_id' => (string) ($row->sale_id ?? ''),
                'sale_item_id' => (string) ($row->sale_item_id ?? ''),
                'business_date' => (string) $row->business_date,
                'product' => (string) $row->product_name, 'variant' => (string) ($row->variant_name ?? ''),
                'quantity_sold' => (string) $row->quantity_sold,
                'historical_hpp' => (string) $row->historical_hpp,
                'allocation_cost' => (string) $row->allocation_cost,
                'final_hpp' => (string) $row->final_hpp,
                'ingredients' => json_decode((string) $row->ingredient_snapshot, true, 512, JSON_THROW_ON_ERROR),
            ];
        })->all();
    }

    private function assertStoredLines(object $run): void
    {
        $lines = $this->sourceLines((string) $run->id);
        if (count($lines) !== (int) $run->sale_item_count) {
            throw ValidationException::withMessages(['run_id' => ['Jumlah snapshot item berubah.']]);
        }
        $hash = hash('sha256', json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!hash_equals((string) $run->lines_hash, $hash)) {
            throw ValidationException::withMessages(['run_id' => ['Rincian snapshot berubah sejak prepare.']]);
        }
        $historical = 0; $allocation = 0; $final = 0; $ingredientCount = 0;
        foreach ($lines as $row) {
            $historical += FinalCogsI27Math::cents($row['historical_hpp']);
            $allocation += FinalCogsI27Math::cents($row['allocation_cost']);
            $final += FinalCogsI27Math::cents($row['final_hpp']);
            $ingredientCount += count($row['ingredients']);
        }
        if ($ingredientCount !== (int) $run->ingredient_line_count ||
            $historical !== FinalCogsI27Math::cents((string) $run->historical_hpp) ||
            $allocation !== FinalCogsI27Math::cents((string) $run->variance_adjustment) ||
            $final !== FinalCogsI27Math::cents((string) $run->final_hpp) ||
            $final !== $historical + $allocation) {
            throw ValidationException::withMessages(['run_id' => ['Total snapshot item tidak rekonsiliasi.']]);
        }
    }

    public function audit(string $id, string $outletId): array
    {
        $run = DB::table('cogs_v13_final_runs')->where('id', $id)->where('outlet_id', $outletId)->first();
        if (!$run) abort(404);
        $this->assertSignature($run);
        $this->assertStoredLines($run);
        try {
            [$report, $snapshot] = $this->fresh((string) $run->variance_id, $outletId);
            $unchanged = hash_equals((string) $run->source_fingerprint, (string) $report['fingerprint'])
                && hash_equals((string) $run->snapshot_hash, (string) $snapshot['snapshot_hash']);
            $error = null;
        } catch (\Throwable $e) {
            $unchanged = false;
            $error = $e->getMessage();
        }
        return [
            'run_id' => $id, 'status' => $run->status, 'snapshot_integrity' => 'verified',
            'canonical_source_unchanged' => $unchanged,
            'canonical_source_message' => $error,
            'finance_posting' => 'not_posted_by_i27',
            'finance_note' => 'Persetujuan ini hanya menyimpan snapshot HPP final dan tidak memposting jurnal ke buku besar. Periksa rekonsiliasi keuangan sebelum membuat jurnal.',
        ];
    }

    public function list(string $outletId, int $page, int $perPage): array
    {
        $pager = DB::table('cogs_v13_final_runs')->where('outlet_id', $outletId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        return ['rows' => $pager->items(), 'pagination' => [
            'current_page' => $pager->currentPage(), 'last_page' => $pager->lastPage(),
            'total' => $pager->total(), 'per_page' => $pager->perPage(),
        ]];
    }

    public function details(string $id, string $outletId, int $page, int $perPage): array
    {
        $run = DB::table('cogs_v13_final_runs')->where('id', $id)->where('outlet_id', $outletId)->first();
        if (!$run) abort(404, 'Proposal tidak ditemukan pada scope outlet.');
        $pager = DB::table('cogs_v13_final_lines')->where('run_id', $id)
            ->orderBy('consumption_id')->paginate($perPage, ['*'], 'page', $page);
        $events = DB::table('cogs_v13_final_events')->where('run_id', $id)->orderBy('created_at')->get();
        // Summary by product/variant is derived exclusively from approved/staged immutable line snapshots.
        $productSummary = DB::table('cogs_v13_final_lines')->where('run_id', $id)
            ->select('product_name', 'variant_name')
            ->selectRaw('COUNT(*) AS item_count, SUM(historical_hpp) AS historical_hpp, SUM(allocation_cost) AS allocation_cost, SUM(final_hpp) AS final_hpp')
            ->groupBy('product_name', 'variant_name')
            ->orderByDesc('final_hpp')->limit(150)->get();
        return [
            'run' => $run,
            'lines' => collect($pager->items())->map(static function ($line): array {
                $v = (array) $line;
                $v['ingredient_snapshot'] = json_decode((string) $line->ingredient_snapshot, true);
                return $v;
            })->all(),
            'events' => $events, 'product_summary' => $productSummary,
            'pagination' => ['current_page' => $pager->currentPage(), 'last_page' => $pager->lastPage(), 'total' => $pager->total()],
            'finance_posting' => 'not_posted_by_i27',
            'scope_warning' => 'HPP final hanya untuk item dengan snapshot konsumsi eligible dalam periode variance; bukan total COGS semua sale.',
        ];
    }
}
