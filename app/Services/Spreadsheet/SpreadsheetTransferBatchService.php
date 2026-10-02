<?php

namespace App\Services\Spreadsheet;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class SpreadsheetTransferBatchService
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_CANCEL_REQUESTED = 'CANCEL_REQUESTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    private const TERMINAL = [self::STATUS_CANCELLED, self::STATUS_COMPLETED, self::STATUS_FAILED];

    public function createImportBatch(
        User $user,
        string $moduleKey,
        UploadedFile $file,
        int $chunkSize = 30,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): array {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $chunkSize = max(1, min(500, $chunkSize));
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path)) throw new InvalidArgumentException('File XLSX tidak dapat dibaca.');
        $sha = hash_file('sha256', $path);
        $size = (int) ($file->getSize() ?: filesize($path) ?: 0);

        if ($idempotencyKey !== null) {
            $existing = DB::table('spreadsheet_transfer_batches')
                ->where('user_id', (string) $user->id)
                ->where('module_key', $moduleKey)
                ->where('operation', 'IMPORT')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if ($existing->file_sha256 && ! hash_equals((string) $existing->file_sha256, $sha)) {
                    throw new InvalidArgumentException('Idempotency key sudah digunakan untuk file berbeda. Gunakan sesi import baru.');
                }
                return $this->formatBatch($existing);
            }
        }

        $id = (string) Str::ulid();
        $storedPath = "spreadsheet-transfers/{$id}/source.xlsx";
        $stream = fopen($path, 'rb');
        if ($stream === false || ! Storage::disk('local')->put($storedPath, $stream)) {
            if (is_resource($stream)) fclose($stream);
            throw new RuntimeException('Source XLSX gagal disimpan ke private storage.');
        }
        if (is_resource($stream)) fclose($stream);

        try {
            DB::table('spreadsheet_transfer_batches')->insert([
                'id' => $id,
                'user_id' => (string) $user->id,
                'module_key' => $moduleKey,
                'operation' => 'IMPORT',
                'status' => self::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'original_filename' => $this->safeFilename($file->getClientOriginalName()),
                'source_path' => $storedPath,
                'file_sha256' => $sha,
                'file_size' => $size,
                'chunk_size' => $chunkSize,
                'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory("spreadsheet-transfers/{$id}");
            throw $e;
        }

        return $this->findOwned($id, $user);
    }

    public function createExportBatch(User $user, string $moduleKey, int $chunkSize = 500, ?string $idempotencyKey = null, array $metadata = []): array
    {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $chunkSize = max(1, min(5000, $chunkSize));
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        if ($idempotencyKey !== null) {
            $existing = DB::table('spreadsheet_transfer_batches')
                ->where('user_id', (string) $user->id)->where('module_key', $moduleKey)
                ->where('operation', 'EXPORT')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $this->formatBatch($existing);
        }

        $id = (string) Str::ulid();
        DB::table('spreadsheet_transfer_batches')->insert([
            'id' => $id, 'user_id' => (string) $user->id, 'module_key' => $moduleKey,
            'operation' => 'EXPORT', 'status' => self::STATUS_PENDING,
            'idempotency_key' => $idempotencyKey, 'chunk_size' => $chunkSize,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->findOwned($id, $user);
    }

    public function findOwned(string $id, User $user): array
    {
        $row = DB::table('spreadsheet_transfer_batches')->where('id', $id)->where('user_id', (string) $user->id)->first();
        if (! $row) throw new InvalidArgumentException('Batch spreadsheet tidak ditemukan.');
        return $this->formatBatch($row);
    }

    public function sourceAbsolutePath(string $id, User $user): string
    {
        $batch = $this->findOwned($id, $user);
        if (($batch['operation'] ?? '') !== 'IMPORT' || empty($batch['source_path'])) {
            throw new InvalidArgumentException('Batch ini tidak memiliki source XLSX import.');
        }
        $path = Storage::disk('local')->path((string) $batch['source_path']);
        if (! is_file($path)) throw new RuntimeException('Source XLSX batch tidak ditemukan di storage.');
        return $path;
    }

    public function markRunning(string $id, User $user, int $sourceRows, int $offset = 0, ?int $chunkSize = null): array
    {
        return DB::transaction(function () use ($id, $user, $sourceRows, $offset, $chunkSize): array {
            $row = $this->lockOwnedRow($id, $user);
            if (in_array($row->status, self::TERMINAL, true)) return $this->formatBatch($row);
            if ($row->status === self::STATUS_CANCEL_REQUESTED) return $this->cancelNow($row);

            $size = max(1, min(5000, $chunkSize ?: (int) $row->chunk_size ?: 30));
            $sourceRows = max(0, $sourceRows);
            $effectiveOffset = max((int) $row->last_offset, max(0, $offset));
            $totalChunks = $sourceRows > 0 ? (int) ceil($sourceRows / $size) : 0;
            $now = now();
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
                'status' => self::STATUS_RUNNING,
                'source_rows' => $sourceRows,
                'chunk_size' => $size,
                // A retry of an older chunk must never rewind persisted progress.
                'last_offset' => $effectiveOffset,
                'current_chunk' => $sourceRows > 0 ? min(max(1, $totalChunks), (int) floor($effectiveOffset / $size) + 1) : 1,
                'total_chunks' => $totalChunks,
                'started_at' => $row->started_at ?: $now,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);
            return $this->findOwned($id, $user);
        });
    }

    /**
     * Execute a module mutation and persist its canonical row ledger in one transaction.
     *
     * The batch row is locked before checking spreadsheet_transfer_rows. This makes an
     * overlapping browser retry safe: request B waits for request A, then sees the row
     * outcome already committed and DOES NOT execute the UPSERT callback again.
     *
     * @param callable():array<string,mixed> $processor
     * @return array{existing:bool,cancelled:bool,outcome:?array<string,mixed>}
     */
    public function processRowOnce(string $id, User $user, int $rowNumber, callable $processor): array
    {
        if ($rowNumber < 1) throw new InvalidArgumentException('Row number harus >= 1.');

        return DB::transaction(function () use ($id, $user, $rowNumber, $processor): array {
            $batch = $this->lockOwnedRow($id, $user);
            $existing = DB::table('spreadsheet_transfer_rows')
                ->where('batch_id', $id)
                ->where('row_number', $rowNumber)
                ->first();

            if ($existing) {
                return ['existing' => true, 'cancelled' => false, 'outcome' => $this->formatRowOutcome($existing)];
            }

            if (in_array($batch->status, [self::STATUS_CANCEL_REQUESTED, self::STATUS_CANCELLED], true)) {
                return ['existing' => false, 'cancelled' => true, 'outcome' => null];
            }
            if (in_array($batch->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
                throw new InvalidArgumentException('Batch spreadsheet sudah berada pada status terminal dan baris ini belum pernah diproses.');
            }

            $outcome = $processor();
            if (! is_array($outcome)) throw new InvalidArgumentException('Processor row spreadsheet harus mengembalikan array outcome.');

            $status = strtoupper(trim((string) ($outcome['status'] ?? 'UNCHANGED')));
            if (! in_array($status, ['INSERTED', 'UPDATED', 'UNCHANGED', 'RESTORED', 'SKIPPED', 'ERROR'], true)) {
                throw new InvalidArgumentException("Status row spreadsheet tidak valid: {$status}");
            }
            $rowKey = isset($outcome['row_key']) && $outcome['row_key'] !== '' ? (string) $outcome['row_key'] : null;
            $fingerprint = isset($outcome['fingerprint']) && $outcome['fingerprint'] !== '' ? strtolower((string) $outcome['fingerprint']) : null;
            $details = is_array($outcome['details'] ?? null) ? $outcome['details'] : [];
            $errors = is_array($outcome['errors'] ?? null) ? $outcome['errors'] : [];
            if ($errors !== []) $status = 'ERROR';

            DB::table('spreadsheet_transfer_rows')->insert([
                'id' => (string) Str::ulid(),
                'batch_id' => $id,
                'row_number' => $rowNumber,
                'row_key' => $rowKey,
                'status' => $status,
                'fingerprint' => $fingerprint,
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'errors_json' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = match ($status) {
                'INSERTED' => 'inserted_rows',
                'UPDATED' => 'updated_rows',
                'RESTORED' => 'restored_rows',
                'SKIPPED' => 'skipped_rows',
                'ERROR' => 'error_rows',
                default => 'unchanged_rows',
            };
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->increment($counter);
            // "processed" follows the existing Data Squad UX: successful non-skipped rows only.
            if (! in_array($status, ['SKIPPED', 'ERROR'], true)) {
                DB::table('spreadsheet_transfer_batches')->where('id', $id)->increment('processed_rows');
            }
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

            $fresh = DB::table('spreadsheet_transfer_rows')
                ->where('batch_id', $id)
                ->where('row_number', $rowNumber)
                ->first();

            return ['existing' => false, 'cancelled' => false, 'outcome' => $this->formatRowOutcome($fresh)];
        });
    }

    /**
     * Records one canonical row result once. Retrying the same chunk is safe:
     * the unique (batch_id,row_number) contract returns false rather than double-counting.
     */
    public function recordRowOutcome(
        string $id,
        User $user,
        int $rowNumber,
        string $status,
        ?string $rowKey = null,
        ?string $fingerprint = null,
        array $details = [],
        array $errors = []
    ): bool {
        $status = strtoupper(trim($status));
        if (! in_array($status, ['INSERTED', 'UPDATED', 'UNCHANGED', 'RESTORED', 'SKIPPED', 'ERROR'], true)) {
            throw new InvalidArgumentException("Status row spreadsheet tidak valid: {$status}");
        }
        if ($rowNumber < 1) throw new InvalidArgumentException('Row number harus >= 1.');
        $this->findOwned($id, $user);

        return DB::transaction(function () use ($id, $rowNumber, $status, $rowKey, $fingerprint, $details, $errors): bool {
            $inserted = DB::table('spreadsheet_transfer_rows')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'batch_id' => $id, 'row_number' => $rowNumber,
                'row_key' => $rowKey, 'status' => $status,
                'fingerprint' => $fingerprint ? strtolower($fingerprint) : null,
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'errors_json' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ((int) $inserted !== 1) return false;

            $column = match ($status) {
                'INSERTED' => 'inserted_rows', 'UPDATED' => 'updated_rows', 'RESTORED' => 'restored_rows',
                'SKIPPED' => 'skipped_rows', 'ERROR' => 'error_rows', default => 'unchanged_rows',
            };
            $increments = [$column => 1];
            if (! in_array($status, ['SKIPPED', 'ERROR'], true)) $increments['processed_rows'] = 1;
            foreach ($increments as $name => $amount) DB::table('spreadsheet_transfer_batches')->where('id', $id)->increment($name, $amount);
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update(['heartbeat_at' => now(), 'updated_at' => now()]);
            return true;
        });
    }

    public function advance(string $id, User $user, int $nextOffset, ?array $result = null): array
    {
        return DB::transaction(function () use ($id, $user, $nextOffset, $result): array {
            $row = $this->lockOwnedRow($id, $user);
            if ($row->status === self::STATUS_CANCEL_REQUESTED) return $this->cancelNow($row);
            if (in_array($row->status, self::TERMINAL, true)) return $this->formatBatch($row);

            $updates = [
                // Old/retried chunks may acknowledge progress, but may never rewind it.
                'last_offset' => max((int) $row->last_offset, max(0, $nextOffset)),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ];
            if ($result !== null) $updates['result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update($updates);
            return $this->findOwned($id, $user);
        });
    }

    public function complete(string $id, User $user, ?string $outputPath = null, ?array $result = null): array
    {
        return DB::transaction(function () use ($id, $user, $outputPath, $result): array {
            $row = $this->lockOwnedRow($id, $user);
            if ($row->status === self::STATUS_CANCEL_REQUESTED) return $this->cancelNow($row);
            if (in_array($row->status, self::TERMINAL, true)) return $this->formatBatch($row);

            $updates = [
                'status' => self::STATUS_COMPLETED,
                'last_offset' => max((int) $row->last_offset, (int) $row->source_rows),
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
                'failure_message' => null,
            ];
            if ($outputPath !== null) $updates['output_path'] = $outputPath;
            if ($result !== null) $updates['result_json'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update($updates);
            return $this->findOwned($id, $user);
        });
    }

    public function fail(string $id, User $user, string $message, ?array $result = null): array
    {
        return DB::transaction(function () use ($id, $user, $message, $result): array {
            $row = $this->lockOwnedRow($id, $user);
            // Never turn a successfully completed/cancelled batch into FAILED because of a late retry.
            if (in_array($row->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                return $this->formatBatch($row);
            }
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
                'status' => self::STATUS_FAILED,
                'failure_message' => substr(trim($message), 0, 4000),
                'result_json' => $result === null ? $row->result_json : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->findOwned($id, $user);
        });
    }

    public function requestCancel(string $id, User $user): array
    {
        return DB::transaction(function () use ($id, $user): array {
            $row = $this->lockOwnedRow($id, $user);
            if (in_array($row->status, self::TERMINAL, true)) return $this->formatBatch($row);
            $now = now();
            $newStatus = $row->status === self::STATUS_PENDING ? self::STATUS_CANCELLED : self::STATUS_CANCEL_REQUESTED;
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
                'status' => $newStatus, 'cancel_requested_at' => $now,
                'finished_at' => $newStatus === self::STATUS_CANCELLED ? $now : null,
                'heartbeat_at' => $now, 'updated_at' => $now,
            ]);
            return $this->findOwned($id, $user);
        });
    }

    public function shouldCancel(string $id, User $user): bool
    {
        $batch = $this->findOwned($id, $user);
        return in_array($batch['status'], [self::STATUS_CANCEL_REQUESTED, self::STATUS_CANCELLED], true);
    }

    public function acknowledgeCancel(string $id, User $user): array
    {
        $this->findOwned($id, $user);
        DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
            'status' => self::STATUS_CANCELLED, 'finished_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ]);
        return $this->findOwned($id, $user);
    }

    public function rowKeyUsedByOtherRow(string $id, User $user, string $rowKey, int $rowNumber): ?array
    {
        $this->findOwned($id, $user);
        $row = DB::table('spreadsheet_transfer_rows')->where('batch_id', $id)->where('row_key', $rowKey)->where('row_number', '<>', $rowNumber)->orderBy('row_number')->first();
        if (! $row) return null;
        return ['row_number' => (int) $row->row_number, 'row_key' => $row->row_key, 'status' => $row->status];
    }

    public function rowOutcome(string $id, User $user, int $rowNumber): ?array
    {
        $this->findOwned($id, $user);
        $row = DB::table('spreadsheet_transfer_rows')->where('batch_id', $id)->where('row_number', $rowNumber)->first();
        if (! $row) return null;
        return $this->formatRowOutcome($row);
    }

    public function rowAlreadyProcessed(string $id, User $user, int $rowNumber): bool
    {
        $this->findOwned($id, $user);
        return DB::table('spreadsheet_transfer_rows')->where('batch_id', $id)->where('row_number', $rowNumber)->exists();
    }

    public function purgeTerminalFiles(string $id, User $user): array
    {
        return DB::transaction(function () use ($id, $user): array {
            $row = $this->lockOwnedRow($id, $user);
            if (! in_array($row->status, self::TERMINAL, true)) {
                throw new InvalidArgumentException('Batch yang masih berjalan tidak dapat dibersihkan. Cancel terlebih dahulu.');
            }
            Storage::disk('local')->deleteDirectory("spreadsheet-transfers/{$id}");
            DB::table('spreadsheet_transfer_batches')->where('id', $id)->update([
                'source_path' => null,
                'output_path' => null,
                'files_purged_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->findOwned($id, $user);
        });
    }

    public function rowResults(string $id, User $user, int $limit = 100): array
    {
        $this->findOwned($id, $user);
        return DB::table('spreadsheet_transfer_rows')->where('batch_id', $id)
            ->orderBy('row_number')->limit(max(1, min(500, $limit)))->get()->map(function ($row): array {
                return [
                    'row_number' => (int) $row->row_number, 'row_key' => $row->row_key, 'status' => $row->status,
                    'details' => $this->decodeJson($row->details_json), 'errors' => $this->decodeJson($row->errors_json),
                    'processed_at' => $row->processed_at,
                ];
            })->all();
    }

    private function lockOwnedRow(string $id, User $user): object
    {
        $row = DB::table('spreadsheet_transfer_batches')->where('id', $id)->where('user_id', (string) $user->id)->lockForUpdate()->first();
        if (! $row) throw new InvalidArgumentException('Batch spreadsheet tidak ditemukan.');
        return $row;
    }

    private function cancelNow(object $row): array
    {
        DB::table('spreadsheet_transfer_batches')->where('id', $row->id)->update([
            'status' => self::STATUS_CANCELLED, 'finished_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ]);
        $fresh = DB::table('spreadsheet_transfer_batches')->where('id', $row->id)->first();
        return $this->formatBatch($fresh);
    }

    private function formatRowOutcome(object $row): array
    {
        return [
            'row_number' => (int) $row->row_number,
            'row_key' => $row->row_key,
            'status' => $row->status,
            'fingerprint' => $row->fingerprint,
            'details' => $this->decodeJson($row->details_json),
            'errors' => $this->decodeJson($row->errors_json),
            'processed_at' => $row->processed_at,
        ];
    }

    private function formatBatch(object $row): array
    {
        $sourceRows = (int) $row->source_rows;
        $offset = (int) $row->last_offset;
        $progress = $sourceRows > 0 ? min(100, round(($offset / $sourceRows) * 100, 2)) : (in_array($row->status, self::TERMINAL, true) ? 100 : 0);
        return [
            'id' => (string) $row->id, 'user_id' => $row->user_id, 'module_key' => $row->module_key,
            'operation' => $row->operation, 'status' => $row->status,
            'idempotency_key' => $row->idempotency_key, 'original_filename' => $row->original_filename,
            'source_path' => $row->source_path, 'output_path' => $row->output_path,
            'file_sha256' => $row->file_sha256, 'file_size' => (int) $row->file_size,
            'chunk_size' => (int) $row->chunk_size, 'source_rows' => $sourceRows,
            'processed' => (int) $row->processed_rows, 'inserted' => (int) $row->inserted_rows,
            'updated' => (int) $row->updated_rows, 'unchanged' => (int) $row->unchanged_rows,
            'restored' => (int) $row->restored_rows, 'skipped' => (int) $row->skipped_rows,
            'error_count' => (int) $row->error_rows, 'last_offset' => $offset,
            'current_chunk' => (int) $row->current_chunk, 'total_chunks' => (int) $row->total_chunks,
            'progress_percent' => $progress, 'metadata' => $this->decodeJson($row->metadata_json),
            'result' => $this->decodeJson($row->result_json), 'failure_message' => $row->failure_message,
            'started_at' => $row->started_at, 'heartbeat_at' => $row->heartbeat_at,
            'cancel_requested_at' => $row->cancel_requested_at, 'finished_at' => $row->finished_at,
            'files_purged_at' => $row->files_purged_at ?? null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeModuleKey(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/', $value)) {
            throw new InvalidArgumentException('module_key tidak valid. Gunakan 2-120 karakter a-z, 0-9, titik, underscore, atau dash.');
        }
        return $value;
    }

    private function normalizeIdempotencyKey(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (strlen($value) > 120 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) throw new InvalidArgumentException('idempotency_key tidak valid.');
        return $value;
    }

    private function safeFilename(string $value): string
    {
        $value = trim(str_replace(["\0", "\r", "\n", '/', '\\'], '_', $value));
        return substr($value !== '' ? $value : 'import.xlsx', 0, 240);
    }
}
