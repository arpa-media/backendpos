<?php

namespace App\Services\Finance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceSummaryXlsxExportService
{
    private const MAX_ROWS = 100000;
    private const MAX_COLUMNS = 60;
    private const MAX_CELLS = 1500000;

    public function __construct(private readonly FinanceOpenXmlXlsxWriter $xlsx) {}

    /**
     * @return array{path:string,filename:string,row_count:int}
     */
    public function build(array $payload, ?string $actorName = null): array
    {
        $sourceRows = $payload['rows'] ?? null;
        if (! is_array($sourceRows)) {
            throw new InvalidArgumentException('Rows export XLSX tidak valid.');
        }
        if (count($sourceRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Export XLSX dibatasi maksimal '.number_format(self::MAX_ROWS).' baris per file. Persempit filter laporan.');
        }

        $normalizedRows = [];
        $maxColumns = 1;
        $cellCount = 0;
        foreach ($sourceRows as $rowIndex => $row) {
            if (! is_array($row)) {
                $row = [$row];
            }
            $row = array_values($row);
            if (count($row) > self::MAX_COLUMNS) {
                throw new InvalidArgumentException('Export XLSX dibatasi maksimal '.self::MAX_COLUMNS.' kolom per baris.');
            }
            $cellCount += count($row);
            if ($cellCount > self::MAX_CELLS) {
                throw new InvalidArgumentException('Ukuran export XLSX terlalu besar. Persempit filter laporan.');
            }
            $normalizedRows[] = array_map(fn ($value) => $this->normalizeValue($value), $row);
            $maxColumns = max($maxColumns, count($row));
        }

        if ($normalizedRows === []) {
            $normalizedRows = [['Tidak ada data']];
        }

        $styledRows = $this->styleRows($normalizedRows);
        $widths = $this->columnWidths($normalizedRows, $maxColumns);
        $merges = $this->mergeRanges($normalizedRows, $maxColumns);

        $filename = $this->xlsxFilename((string) ($payload['filename'] ?? 'finance_summary.xlsx'));
        $sheetName = (string) ($payload['sheet_name'] ?? $payload['title'] ?? 'Finance Summary');
        $freezeRow = max(0, min((int) ($payload['freeze_row'] ?? 0), 100));
        $actorName = trim((string) $actorName) ?: 'POS Finance';

        $tmp = storage_path('app/tmp/finance-summary-xlsx');
        if (! is_dir($tmp) && ! mkdir($tmp, 0775, true) && ! is_dir($tmp)) {
            throw new InvalidArgumentException('Folder temporary export Finance tidak dapat dibuat.');
        }
        $path = $tmp.'/'.Str::ulid().'.xlsx';
        $this->xlsx->write($path, $sheetName, $styledRows, $merges, $widths, $freezeRow, $actorName);

        return ['path' => $path, 'filename' => $filename, 'row_count' => count($normalizedRows)];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_numeric($value) && ! preg_match('/^0\d+$/', trim((string) $value))) {
            $numeric = (float) $value;
            return is_finite($numeric) ? $numeric : 0;
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $text = (string) $value;
        if (mb_strlen($text) > 32767) $text = mb_substr($text, 0, 32767);
        return $text;
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function styleRows(array $rows): array
    {
        $result = [];
        $metadataPhase = true;
        foreach ($rows as $index => $row) {
            $nonEmpty = array_values(array_filter($row, fn ($v) => $v !== null && $v !== ''));
            $isBlank = $nonEmpty === [];
            if ($index === 0) {
                $result[] = ['cells' => array_map(fn ($v) => FinanceOpenXmlXlsxWriter::cell($v, FinanceOpenXmlXlsxWriter::STYLE_TITLE, 's'), $row), 'height' => 28];
                continue;
            }
            if ($isBlank) {
                $metadataPhase = false;
                $result[] = ['cells' => []];
                continue;
            }

            $first = strtoupper(trim((string) ($row[0] ?? '')));
            $isTotal = in_array($first, ['TOTAL', 'GRAND TOTAL', 'TOTAL SUMMARY'], true) || str_starts_with($first, 'TOTAL ');
            $isSection = count($nonEmpty) === 1 && ! is_numeric($nonEmpty[0]);
            $isHeader = ! $metadataPhase && count($nonEmpty) >= 2 && $this->allText($nonEmpty);

            $cells = [];
            foreach ($row as $column => $value) {
                if ($metadataPhase) {
                    $style = $column === 0 ? FinanceOpenXmlXlsxWriter::STYLE_META_LABEL : FinanceOpenXmlXlsxWriter::STYLE_META_VALUE;
                    $cells[] = FinanceOpenXmlXlsxWriter::cell($value, $style, is_numeric($value) ? 'n' : 's');
                    continue;
                }
                if ($isSection) {
                    $cells[] = FinanceOpenXmlXlsxWriter::cell($value, FinanceOpenXmlXlsxWriter::STYLE_SECTION, 's');
                    continue;
                }
                if ($isHeader) {
                    $cells[] = FinanceOpenXmlXlsxWriter::cell($value, FinanceOpenXmlXlsxWriter::STYLE_HEADER, 's');
                    continue;
                }
                if ($isTotal) {
                    $style = is_numeric($value) ? FinanceOpenXmlXlsxWriter::STYLE_TOTAL_NUMBER : FinanceOpenXmlXlsxWriter::STYLE_TOTAL_TEXT;
                    $cells[] = FinanceOpenXmlXlsxWriter::cell($value, $style, is_numeric($value) ? 'n' : 's');
                    continue;
                }
                $style = is_numeric($value) ? FinanceOpenXmlXlsxWriter::STYLE_NUMBER : FinanceOpenXmlXlsxWriter::STYLE_TEXT;
                $cells[] = FinanceOpenXmlXlsxWriter::cell($value, $style, is_numeric($value) ? 'n' : 's');
            }
            $result[] = ['cells' => $cells, 'height' => $isHeader ? 24 : null];
        }
        return $result;
    }

    private function allText(array $values): bool
    {
        foreach ($values as $value) {
            if (is_int($value) || is_float($value)) return false;
        }
        return true;
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function columnWidths(array $rows, int $maxColumns): array
    {
        $widths = array_fill(1, $maxColumns, 12.0);
        foreach (array_slice($rows, 0, 5000) as $row) {
            foreach ($row as $index => $value) {
                $text = is_scalar($value) ? (string) $value : '';
                $length = mb_strlen($text);
                $column = $index + 1;
                $widths[$column] = max($widths[$column] ?? 12.0, min(48.0, max(12.0, $length + 2.0)));
            }
        }
        return $widths;
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function mergeRanges(array $rows, int $maxColumns): array
    {
        if ($maxColumns <= 1) return [];
        $merges = ['A1:'.$this->columnLetter($maxColumns).'1'];
        $metadataPhase = true;
        foreach ($rows as $index => $row) {
            if ($index === 0) continue;
            $nonEmpty = array_values(array_filter($row, fn ($v) => $v !== null && $v !== ''));
            if ($nonEmpty === []) {
                $metadataPhase = false;
                continue;
            }
            if (! $metadataPhase && count($nonEmpty) === 1 && ! is_numeric($nonEmpty[0])) {
                $rowNo = $index + 1;
                $merges[] = 'A'.$rowNo.':'.$this->columnLetter($maxColumns).$rowNo;
            }
        }
        return $merges;
    }

    private function xlsxFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/\.csv$/i', '.xlsx', $filename) ?: $filename;
        if (! str_ends_with(strtolower($filename), '.xlsx')) $filename .= '.xlsx';
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'finance_summary.xlsx';
        return trim($filename, '_');
    }

    private function columnLetter(int $number): string
    {
        $letter = '';
        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)).$letter;
            $number = intdiv($number, 26);
        }
        return $letter;
    }
}
