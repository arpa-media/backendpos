<?php

namespace App\Services\Spreadsheet;

use App\Models\User;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SpreadsheetChunkImportService
{
    public function __construct(
        private readonly SpreadsheetTransferBatchService $batches,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    /**
     * @param array<string,array<int,string>|string> $headerAliases canonical => aliases
     * @param array<int,string> $requiredHeaders
     * @param callable(array<string,string>,array<string,mixed>):array<string,mixed> $handler
     *
     * Handler return contract:
     * [status => INSERTED|UPDATED|UNCHANGED|RESTORED|SKIPPED|ERROR,
     *  row_key => ?, details => [], errors => [], fingerprint => ?]
     */
    public function process(
        User $user,
        string $batchId,
        int $offset,
        array $headerAliases,
        array $requiredHeaders,
        callable $handler,
        ?int $chunkSize = null,
        ?string $expectedModuleKey = null,
    ): array {
        $ownedBatch = $this->batches->findOwned($batchId, $user);
        if (($ownedBatch['operation'] ?? '') !== 'IMPORT') throw new InvalidArgumentException('Batch bukan operasi IMPORT.');
        if ($expectedModuleKey !== null && ($ownedBatch['module_key'] ?? '') !== $expectedModuleKey) {
            throw new InvalidArgumentException('Batch tidak sesuai module_key endpoint ini.');
        }

        $offset = max(0, $offset);
        $lastOffset = max(0, (int) ($ownedBatch['last_offset'] ?? 0));
        // Replaying an older offset is valid after timeout; jumping ahead is not.
        if ($offset > $lastOffset) {
            throw new InvalidArgumentException("Offset import tidak berurutan. Offset berikutnya yang diizinkan: {$lastOffset}.");
        }

        if (($ownedBatch['status'] ?? '') === SpreadsheetTransferBatchService::STATUS_FAILED) {
            throw new InvalidArgumentException('Batch spreadsheet sudah FAILED. Buat sesi import baru setelah memperbaiki file/template.');
        }

        try {
            $prepared = $this->prepareWorkbook($user, $batchId, $headerAliases, $requiredHeaders, $ownedBatch);
            $header = $prepared['header'];
            $sourceRows = (int) $prepared['source_rows'];
        } catch (InvalidArgumentException $e) {
            // Header/file errors are deterministic for this source and should not leave a PENDING batch forever.
            $this->batches->fail($batchId, $user, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $message = 'Workbook XLSX gagal dibaca: '.$e->getMessage();
            $this->batches->fail($batchId, $user, $message);
            throw new InvalidArgumentException($message, 0, $e);
        }
        $batch = $this->batches->markRunning($batchId, $user, $sourceRows, $offset, $chunkSize);
        if (in_array($batch['status'], [SpreadsheetTransferBatchService::STATUS_CANCELLED, SpreadsheetTransferBatchService::STATUS_CANCEL_REQUESTED], true)) {
            $batch = $this->batches->acknowledgeCancel($batchId, $user);
            return [
                'batch' => $batch,
                'result' => SpreadsheetImportResult::empty()->toArray(),
                'chunk' => $this->chunkMeta($offset, $sourceRows, (int) $batch['chunk_size'], false, true),
            ];
        }

        $size = max(1, (int) ($batch['chunk_size'] ?? 30));
        $slice = $this->readPreparedRows($batchId, $offset, $size);
        $result = SpreadsheetImportResult::empty();
        $cancelled = false;
        $consumed = 0;

        foreach ($slice as $index => $entry) {
            $rowNumber = $offset + $index + 2; // worksheet line: header is row 1
            if ($this->batches->shouldCancel($batchId, $user)) { $cancelled = true; break; }

            $once = $this->batches->processRowOnce($batchId, $user, $rowNumber, function () use ($entry, $header, $handler, $batchId, $rowNumber, $offset, $size, $user): array {
                if (($entry['blank'] ?? false) === true) {
                    return [
                        'status' => 'SKIPPED',
                        'row_key' => null,
                        'details' => ['reason' => 'blank_row'],
                        'errors' => [],
                        'fingerprint' => null,
                    ];
                }

                $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
                $fingerprint = (string) ($entry['fingerprint'] ?? hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

                try {
                    $outcome = $handler($data, [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'offset' => $offset,
                        'chunk_size' => $size,
                        'header' => $header,
                        'user' => $user,
                    ]);
                    if (! is_array($outcome)) throw new InvalidArgumentException('Handler import harus mengembalikan array outcome.');

                    $errors = is_array($outcome['errors'] ?? null) ? $outcome['errors'] : [];
                    return [
                        'status' => $errors !== [] ? 'ERROR' : strtoupper((string) ($outcome['status'] ?? 'UNCHANGED')),
                        'row_key' => isset($outcome['row_key']) ? (string) $outcome['row_key'] : null,
                        'details' => is_array($outcome['details'] ?? null) ? $outcome['details'] : [],
                        'errors' => $errors,
                        'fingerprint' => (string) ($outcome['fingerprint'] ?? $fingerprint),
                    ];
                } catch (Throwable $e) {
                    return [
                        'status' => 'ERROR',
                        'row_key' => null,
                        'details' => [],
                        'errors' => [[
                            'column' => 'Server',
                            'field' => 'server',
                            'value' => '',
                            'message' => $e->getMessage(),
                        ]],
                        'fingerprint' => $fingerprint,
                    ];
                }
            });

            if (($once['cancelled'] ?? false) === true) { $cancelled = true; break; }
            $outcome = $once['outcome'] ?? null;
            if (! is_array($outcome)) throw new InvalidArgumentException('Outcome row spreadsheet tidak tersedia.');

            $result->addRow(
                (string) ($outcome['status'] ?? 'UNCHANGED'),
                $rowNumber,
                isset($outcome['row_key']) ? (string) $outcome['row_key'] : null,
                is_array($outcome['details'] ?? null) ? $outcome['details'] : [],
                is_array($outcome['errors'] ?? null) ? $outcome['errors'] : [],
            );
            $consumed++;
        }

        $nextOffset = min($sourceRows, $offset + $consumed);
        if ($cancelled) {
            $batch = $this->batches->acknowledgeCancel($batchId, $user);
            return [
                'batch' => $batch,
                'result' => $result->toArray(),
                'workbook_total_rows' => $sourceRows,
                'chunk' => $this->chunkMeta($nextOffset, $sourceRows, $size, false, true),
                'header' => ['map' => $header['map'], 'labels' => $header['labels']],
            ];
        }

        $hasMore = $nextOffset < $sourceRows;
        $batch = $this->batches->advance($batchId, $user, $nextOffset);
        if (in_array($batch['status'], [SpreadsheetTransferBatchService::STATUS_CANCELLED, SpreadsheetTransferBatchService::STATUS_CANCEL_REQUESTED], true)) {
            $batch = $this->batches->acknowledgeCancel($batchId, $user);
            $hasMore = false;
            $cancelled = true;
        } elseif (! $hasMore) {
            $batch = $this->batches->complete($batchId, $user);
        }

        return [
            'batch' => $batch,
            'result' => $result->toArray(),
            'workbook_total_rows' => $sourceRows,
            'chunk' => $this->chunkMeta($nextOffset, $sourceRows, $size, $hasMore, $cancelled),
            'header' => ['map' => $header['map'], 'labels' => $header['labels']],
        ];
    }

    /** @return array{map:array<string,int>,labels:array<string,string>} */
    public function resolveHeader(array $rawHeader, array $headerAliases, array $requiredHeaders = []): array
    {
        $aliasLookup = [];
        foreach ($headerAliases as $canonical => $aliases) {
            foreach (array_merge([$canonical], is_array($aliases) ? $aliases : [$aliases]) as $alias) {
                $key = $this->normalizeHeader((string) $alias);
                if ($key !== '') $aliasLookup[$key] = (string) $canonical;
            }
        }

        $map = []; $labels = []; $duplicates = [];
        foreach (array_values($rawHeader) as $index => $label) {
            $normalized = $this->normalizeHeader((string) $label);
            if ($normalized === '' || ! isset($aliasLookup[$normalized])) continue;
            $canonical = $aliasLookup[$normalized];
            if (isset($map[$canonical])) { $duplicates[$canonical][] = (string) $label; continue; }
            $map[$canonical] = $index; $labels[$canonical] = (string) $label;
        }

        $missing = array_values(array_filter($requiredHeaders, fn ($field) => ! array_key_exists($field, $map)));
        if ($missing !== []) throw new InvalidArgumentException('Header wajib tidak ditemukan: '.implode(', ', $missing).'.');
        if ($duplicates !== []) throw new InvalidArgumentException('Header duplikat mengarah ke field sama: '.implode(', ', array_keys($duplicates)).'.');
        return ['map' => $map, 'labels' => $labels];
    }

    /**
     * Parse XLSX only once per batch. Canonical rows are persisted as private JSONL plus
     * a byte-offset index, so later chunks seek directly to the requested row range.
     *
     * @return array{source_rows:int,header:array{map:array<string,int>,labels:array<string,string>}}
     */
    private function prepareWorkbook(User $user, string $batchId, array $headerAliases, array $requiredHeaders, array $batch): array
    {
        $disk = Storage::disk('local');
        $dir = "spreadsheet-transfers/{$batchId}/prepared";
        $disk->makeDirectory($dir);
        $manifestPath = $disk->path("{$dir}/manifest.json");
        $rowsPath = $disk->path("{$dir}/rows.jsonl");
        $offsetsPath = $disk->path("{$dir}/offsets.json");
        $lockPath = $disk->path("{$dir}/prepare.lock");
        $schemaFingerprint = hash('sha256', json_encode([$headerAliases, array_values($requiredHeaders)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $lock = fopen($lockPath, 'c+');
        if ($lock === false) throw new RuntimeException('Lock preparation spreadsheet tidak dapat dibuat.');
        try {
            if (! flock($lock, LOCK_EX)) throw new RuntimeException('Preparation spreadsheet tidak dapat dikunci.');

            $manifest = $this->readPreparedManifest($manifestPath);
            if ($manifest && is_file($rowsPath) && is_file($offsetsPath)) {
                if (($manifest['schema_fingerprint'] ?? '') !== $schemaFingerprint) {
                    throw new InvalidArgumentException('Schema/header importer berubah saat batch masih berjalan. Buat sesi import baru.');
                }
                if (($manifest['file_sha256'] ?? '') !== (string) ($batch['file_sha256'] ?? '')) {
                    throw new InvalidArgumentException('Fingerprint source XLSX tidak sama dengan batch. Buat sesi import baru.');
                }
                return [
                    'source_rows' => (int) ($manifest['source_rows'] ?? 0),
                    'header' => is_array($manifest['header'] ?? null) ? $manifest['header'] : ['map' => [], 'labels' => []],
                ];
            }

            $source = $this->batches->sourceAbsolutePath($batchId, $user);
            $rows = $this->xlsx->read($source);
            if ($rows === []) throw new InvalidArgumentException('Workbook tidak memiliki header.');
            $rawHeader = array_shift($rows);
            $header = $this->resolveHeader($rawHeader, $headerAliases, $requiredHeaders);

            $token = bin2hex(random_bytes(5));
            $tmpRows = $rowsPath.'.'.$token.'.tmp';
            $tmpOffsets = $offsetsPath.'.'.$token.'.tmp';
            $tmpManifest = $manifestPath.'.'.$token.'.tmp';
            $handle = fopen($tmpRows, 'wb');
            if ($handle === false) throw new RuntimeException('Cache row spreadsheet tidak dapat dibuat.');

            $offsets = [];
            try {
                foreach (array_values($rows) as $row) {
                    $offsets[] = (int) ftell($handle);
                    if ($this->isBlankRow($row)) {
                        $entry = ['blank' => true, 'data' => [], 'fingerprint' => null];
                    } else {
                        $data = [];
                        foreach ($header['map'] as $canonical => $columnIndex) {
                            $data[$canonical] = $this->normalizeCell($row[$columnIndex] ?? '');
                        }
                        $entry = [
                            'blank' => false,
                            'data' => $data,
                            'fingerprint' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        ];
                    }
                    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($json === false || fwrite($handle, $json."\n") === false) {
                        throw new RuntimeException('Cache row spreadsheet gagal ditulis.');
                    }
                }
            } finally {
                fclose($handle);
            }

            $manifest = [
                'version' => 1,
                'batch_id' => $batchId,
                'file_sha256' => (string) ($batch['file_sha256'] ?? ''),
                'schema_fingerprint' => $schemaFingerprint,
                'source_rows' => count($rows),
                'header' => $header,
                'prepared_at' => now()->toIso8601String(),
            ];
            if (file_put_contents($tmpOffsets, json_encode($offsets, JSON_UNESCAPED_SLASHES)) === false) {
                @unlink($tmpRows); throw new RuntimeException('Index offset spreadsheet gagal ditulis.');
            }
            if (file_put_contents($tmpManifest, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
                @unlink($tmpRows); @unlink($tmpOffsets); throw new RuntimeException('Manifest spreadsheet gagal ditulis.');
            }

            // Atomic publish inside the locked directory. Remove stale/incomplete cache first
            // because Windows rename() does not reliably replace an existing destination.
            @unlink($rowsPath);
            @unlink($offsetsPath);
            @unlink($manifestPath);
            if (! @rename($tmpRows, $rowsPath) || ! @rename($tmpOffsets, $offsetsPath) || ! @rename($tmpManifest, $manifestPath)) {
                @unlink($tmpRows); @unlink($tmpOffsets); @unlink($tmpManifest);
                throw new RuntimeException('Cache spreadsheet gagal dipublikasikan secara atomik.');
            }

            return ['source_rows' => count($rows), 'header' => $header];
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<int,array{blank:bool,data:array<string,string>,fingerprint:?string}> */
    private function readPreparedRows(string $batchId, int $offset, int $size): array
    {
        $disk = Storage::disk('local');
        $dir = "spreadsheet-transfers/{$batchId}/prepared";
        $rowsPath = $disk->path("{$dir}/rows.jsonl");
        $offsetsPath = $disk->path("{$dir}/offsets.json");
        if (! is_file($rowsPath) || ! is_file($offsetsPath)) throw new RuntimeException('Prepared row cache spreadsheet tidak ditemukan.');

        $offsets = json_decode((string) file_get_contents($offsetsPath), true);
        if (! is_array($offsets)) throw new RuntimeException('Index offset spreadsheet rusak.');
        if ($offset >= count($offsets)) return [];

        $handle = fopen($rowsPath, 'rb');
        if ($handle === false) throw new RuntimeException('Prepared row cache spreadsheet tidak dapat dibaca.');
        try {
            if (fseek($handle, (int) $offsets[$offset]) !== 0) throw new RuntimeException('Prepared row cache spreadsheet gagal seek.');
            $result = [];
            for ($i = 0; $i < $size && ($offset + $i) < count($offsets); $i++) {
                $line = fgets($handle);
                if ($line === false) throw new RuntimeException('Prepared row cache spreadsheet terpotong.');
                $entry = json_decode(trim($line), true);
                if (! is_array($entry)) throw new RuntimeException('Prepared row cache spreadsheet berisi JSON tidak valid.');
                $result[] = [
                    'blank' => (bool) ($entry['blank'] ?? false),
                    'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
                    'fingerprint' => isset($entry['fingerprint']) ? (string) $entry['fingerprint'] : null,
                ];
            }
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function readPreparedManifest(string $path): ?array
    {
        if (! is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function chunkMeta(int $nextOffset, int $sourceRows, int $size, bool $hasMore, bool $cancelled): array
    {
        return [
            'enabled' => true, 'source_rows' => $sourceRows, 'chunk_size' => $size,
            'next_offset' => $nextOffset, 'has_more' => $hasMore, 'cancelled' => $cancelled,
            'percent' => $sourceRows > 0 ? round(min(100, ($nextOffset / $sourceRows) * 100), 2) : 100,
        ];
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim($value, '_');
    }

    private function normalizeCell(mixed $value): string
    {
        return trim(str_replace("\xC2\xA0", ' ', (string) $value));
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) if ($this->normalizeCell($value) !== '') return false;
        return true;
    }
}
