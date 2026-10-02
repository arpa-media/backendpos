<?php

namespace App\Services\Spreadsheet;

final class SpreadsheetImportResult
{
    public int $totalRows = 0;
    public int $processed = 0;
    public int $inserted = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $restored = 0;
    public int $skipped = 0;
    public int $errorCount = 0;

    /** @var array<int,array<string,mixed>> */
    public array $errors = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public string $note = '';

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Canonical row statuses shared by every importer.
     */
    public function addRow(string $status, int $rowNumber, ?string $rowKey = null, array $details = [], array $errors = []): self
    {
        $status = strtoupper(trim($status));
        $this->totalRows++;

        if ($status === 'SKIPPED') {
            $this->skipped++;
        } elseif ($status === 'ERROR' || $errors !== []) {
            $this->errorCount++;
            $status = 'ERROR';
        } else {
            $this->processed++;
            match ($status) {
                'INSERTED' => $this->inserted++,
                'UPDATED' => $this->updated++,
                'RESTORED' => $this->restored++,
                default => $this->unchanged++,
            };
        }

        $row = [
            'row_number' => $rowNumber,
            'row_key' => $rowKey,
            'status' => $status,
            'details' => $details,
        ];
        if ($errors !== []) {
            $row['errors'] = $errors;
            $this->errors[] = $row;
        }
        $this->rows[] = $row;

        return $this;
    }

    public function merge(self|array $other): self
    {
        $data = $other instanceof self ? $other->toArray(false) : $other;
        foreach ([
            'total_rows' => 'totalRows', 'processed' => 'processed', 'inserted' => 'inserted',
            'updated' => 'updated', 'unchanged' => 'unchanged', 'restored' => 'restored',
            'skipped' => 'skipped', 'error_count' => 'errorCount',
        ] as $key => $property) {
            $this->{$property} += (int) ($data[$key] ?? 0);
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            $this->errors = array_values(array_merge($this->errors, $data['errors']));
        }
        if (isset($data['rows']) && is_array($data['rows'])) {
            $this->rows = array_values(array_merge($this->rows, $data['rows']));
        }
        if (! empty($data['note'])) $this->note = (string) $data['note'];
        return $this;
    }

    public function toArray(bool $includeRows = true): array
    {
        $status = $this->errorCount === 0 ? 'success' : ($this->processed > 0 ? 'partial' : 'failed');
        $payload = [
            'success' => $this->errorCount === 0,
            'status' => $status,
            'total_rows' => $this->totalRows,
            'processed' => $this->processed,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'restored' => $this->restored,
            'skipped' => $this->skipped,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'note' => $this->note,
        ];
        if ($includeRows) $payload['rows'] = $this->rows;
        return $payload;
    }
}
