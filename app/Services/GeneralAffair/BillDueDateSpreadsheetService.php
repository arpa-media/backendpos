<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\BillDueDate;
use App\Models\Outlet;
use App\Services\Support\SimpleXlsxService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BillDueDateSpreadsheetService
{
    public const HEADERS = ['Bill Type', 'Due Date', 'Outlet Code', 'Customer ID', 'Nominal', 'Notes'];

    public function __construct(private readonly SimpleXlsxService $xlsx) {}

    public function templateResponse()
    {
        return $this->xlsx->download('template_bill_due_date_ga.xlsx', 'Bill Due Date', [
            self::HEADERS,
            ['PLN', now()->endOfMonth()->format('Y-m-d'), 'DPN', '1234567890', '1500000', 'Contoh - hapus baris ini sebelum import'],
            ['INTERNET', now()->endOfMonth()->format('Y-m-d'), 'DPN', 'ORBIT-001', '550000', ''],
            ['AIR', now()->endOfMonth()->format('Y-m-d'), 'DPN', 'PDAM-001', '350000', ''],
        ]);
    }

    public function exportResponse(iterable $rows)
    {
        $sheet = [self::HEADERS];
        foreach ($rows as $row) {
            $sheet[] = [
                $row->bill_type,
                optional($row->due_date)->format('Y-m-d') ?: (string) $row->due_date,
                $row->outlet_code_snapshot,
                $row->customer_account_id,
                (string) $row->nominal,
                $row->notes,
            ];
        }
        return $this->xlsx->download('ga_bill_due_date_'.now()->format('Ymd_His').'.xlsx', 'Bill Due Date', $sheet);
    }

    public function importChunk(UploadedFile $file, int $offset, int $size, ?string $actorId): array
    {
        $rows = $this->xlsx->read($file);
        if (count($rows) < 2) throw new InvalidArgumentException('File import kosong. Minimal harus berisi header dan 1 baris data.');

        $header = array_shift($rows);
        $map = $this->resolveHeaders($header);
        $sourceRows = count($rows);
        $nonEmptyRows = count(array_filter($rows, fn ($row) => $this->rowHasValue($row)));
        $slice = array_slice($rows, max(0, $offset), max(1, min(100, $size)));

        $result = $this->emptyResult();
        $line = $offset + 1;
        foreach ($slice as $row) {
            $line++;
            if (! $this->rowHasValue($row)) { $result['skipped']++; continue; }
            $result['total_rows']++;
            try {
                $payload = $this->mapRow($row, $map, $line);
                $saved = $this->upsert($payload, $actorId);
                $result[$saved['action']]++;
                if ($saved['restored']) $result['restored']++;
                $result['processed']++;
                $result['row_results'][] = [
                    'line' => $line,
                    'action' => $saved['action'],
                    'bill_type' => $payload['bill_type'],
                    'outlet' => $payload['outlet_code_snapshot'].' · '.$payload['outlet_name_snapshot'],
                    'customer_id' => $payload['customer_account_id'],
                    'due_date' => $payload['due_date'],
                    'changed_fields' => $saved['changed_fields'],
                    'restored' => $saved['restored'],
                ];
            } catch (\Throwable $e) {
                $result['error_count']++;
                $result['errors'][] = [
                    'line' => $line,
                    'bill_type' => $this->cell($row, $map['bill_type'] ?? -1),
                    'outlet' => $this->cell($row, $map['outlet_code'] ?? -1),
                    'customer_id' => $this->cell($row, $map['customer_account_id'] ?? -1),
                    'details' => [[
                        'column' => 'Row',
                        'field' => 'row',
                        'value' => implode(' | ', array_map(fn ($v) => trim((string) $v), $row)),
                        'message' => $e->getMessage(),
                    ]],
                    'error_text' => $e->getMessage(),
                ];
            }
        }

        $nextOffset = min($sourceRows, $offset + count($slice));
        $result['workbook_total_rows'] = $nonEmptyRows;
        $result['success'] = $result['error_count'] === 0;
        $result['status'] = $result['success'] ? 'success' : ($result['processed'] > 0 ? 'partial' : 'failed');
        $result['chunk'] = [
            'enabled' => true,
            'offset' => $offset,
            'size' => $size,
            'source_rows' => $sourceRows,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < $sourceRows,
            'percent' => $sourceRows > 0 ? round(min(100, ($nextOffset / $sourceRows) * 100), 2) : 100,
        ];
        return $result;
    }

    public function upsert(array $payload, ?string $actorId): array
    {
        return DB::transaction(function () use ($payload, $actorId): array {
            $existing = BillDueDate::withTrashed()
                ->where('bill_type', $payload['bill_type'])
                ->where('outlet_id', $payload['outlet_id'])
                ->where('customer_account_id', $payload['customer_account_id'])
                ->whereDate('due_date', $payload['due_date'])
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                BillDueDate::query()->create($payload + [
                    'id' => (string) Str::ulid(),
                    'created_by_user_id' => $actorId,
                    'updated_by_user_id' => $actorId,
                ]);
                return ['action' => 'inserted', 'restored' => false, 'changed_fields' => array_keys($payload)];
            }

            $changed = [];
            foreach (['outlet_code_snapshot','outlet_name_snapshot','nominal','notes'] as $field) {
                $before = $field === 'nominal' ? (float) $existing->{$field} : trim((string) ($existing->{$field} ?? ''));
                $after = $field === 'nominal' ? (float) $payload[$field] : trim((string) ($payload[$field] ?? ''));
                if ($before !== $after) $changed[$field] = $payload[$field];
            }
            $restored = $existing->trashed();
            if ($changed || $restored) {
                if ($restored) $existing->restore();
                $existing->forceFill($changed + ['updated_by_user_id' => $actorId])->save();
                return ['action' => 'updated', 'restored' => $restored, 'changed_fields' => array_keys($changed)];
            }
            return ['action' => 'unchanged', 'restored' => false, 'changed_fields' => []];
        }, 3);
    }

    private function resolveHeaders(array $headers): array
    {
        $aliases = [
            'bill_type' => ['bill type','bill_type','jenis','jenis tagihan','kategori'],
            'due_date' => ['due date','due_date','jatuh tempo','tanggal jatuh tempo'],
            'outlet_code' => ['outlet code','outlet_code','kode outlet','outlet'],
            'customer_account_id' => ['customer id','customer_id','id pelanggan','id','nomor pelanggan','no pelanggan'],
            'nominal' => ['nominal','amount','nilai','tagihan'],
            'notes' => ['notes','note','keterangan','catatan'],
        ];
        $normalized = array_map(fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v))), $headers);
        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($normalized as $idx => $name) {
                if (in_array($name, $names, true)) { $map[$field] = $idx; break; }
            }
        }
        $missing = array_values(array_diff(['bill_type','due_date','outlet_code','customer_account_id','nominal'], array_keys($map)));
        if ($missing) throw new InvalidArgumentException('Header wajib tidak ditemukan: '.implode(', ', $missing).'. Gunakan template Bill Due Date terbaru.');
        return $map;
    }

    private function mapRow(array $row, array $map, int $line): array
    {
        $billType = $this->normalizeBillType($this->cell($row, $map['bill_type']));
        $dueDate = $this->normalizeDate($this->cell($row, $map['due_date']));
        $outletText = trim($this->cell($row, $map['outlet_code']));
        $accountId = trim($this->cell($row, $map['customer_account_id']));
        $nominal = $this->normalizeMoney($this->cell($row, $map['nominal']));
        $notes = isset($map['notes']) ? trim($this->cell($row, $map['notes'])) : '';
        if ($outletText === '') throw new InvalidArgumentException("Baris {$line}: Outlet wajib diisi.");
        if ($accountId === '') throw new InvalidArgumentException("Baris {$line}: Customer ID wajib diisi.");
        if ($nominal < 0) throw new InvalidArgumentException("Baris {$line}: Nominal tidak boleh negatif.");

        $outlet = Outlet::query()->whereRaw('UPPER(TRIM(code)) = ?', [mb_strtoupper($outletText)])
            ->orWhereRaw('UPPER(TRIM(name)) = ?', [mb_strtoupper($outletText)])
            ->first();
        if (! $outlet) throw new InvalidArgumentException("Baris {$line}: Outlet '{$outletText}' tidak ditemukan.");

        return [
            'bill_type' => $billType,
            'due_date' => $dueDate,
            'outlet_id' => $outlet->id,
            'outlet_code_snapshot' => $outlet->code,
            'outlet_name_snapshot' => $outlet->name,
            'customer_account_id' => $accountId,
            'nominal' => $nominal,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    private function normalizeBillType(string $value): string
    {
        $key = mb_strtoupper(trim($value));
        $map = [
            'PLN' => 'PLN', 'LISTRIK' => 'PLN', 'LISTRIK/PLN' => 'PLN',
            'INTERNET' => 'INTERNET', 'ORBIT' => 'INTERNET', 'INTERNET/ORBIT' => 'INTERNET',
            'AIR' => 'AIR', 'PDAM' => 'AIR', 'AIR/PDAM' => 'AIR',
        ];
        if (! isset($map[$key])) throw new InvalidArgumentException("Bill Type '{$value}' tidak valid. Gunakan PLN, INTERNET, atau AIR.");
        return $map[$key];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException('Due Date wajib diisi.');
        if (is_numeric($value) && (float) $value >= 20000 && (float) $value <= 90000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->format('Y-m-d');
        }
        foreach (['Y-m-d','d/m/Y','d-m-Y','m/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) return $date->format('Y-m-d');
            } catch (\Throwable) {}
        }
        try { return Carbon::parse($value)->format('Y-m-d'); }
        catch (\Throwable) { throw new InvalidArgumentException("Due Date '{$value}' tidak dapat dibaca. Gunakan format YYYY-MM-DD."); }
    }

    private function normalizeMoney(string $value): float
    {
        $raw = trim(preg_replace('/[^0-9,\.\-]/', '', $value) ?? '');
        if ($raw === '') return 0;
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) $raw = str_replace('.', '', str_replace(',', '.', $raw));
            else $raw = str_replace(',', '', $raw);
        } elseif (substr_count($raw, '.') > 1) $raw = str_replace('.', '', $raw);
        elseif (substr_count($raw, ',') > 1) $raw = str_replace(',', '', $raw);
        elseif (str_contains($raw, ',')) {
            $tail = strlen($raw) - strrpos($raw, ',') - 1;
            $raw = $tail === 3 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
        } elseif (str_contains($raw, '.')) {
            $tail = strlen($raw) - strrpos($raw, '.') - 1;
            if ($tail === 3) $raw = str_replace('.', '', $raw);
        }
        if (! is_numeric($raw)) throw new InvalidArgumentException("Nominal '{$value}' tidak valid.");
        return round((float) $raw, 2);
    }

    private function rowHasValue(array $row): bool
    {
        return count(array_filter($row, fn ($v) => trim((string) $v) !== '')) > 0;
    }

    private function cell(array $row, int $index): string
    {
        return $index >= 0 ? trim((string) ($row[$index] ?? '')) : '';
    }

    private function emptyResult(): array
    {
        return [
            'success' => true, 'status' => 'success', 'total_rows' => 0, 'processed' => 0,
            'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'restored' => 0,
            'skipped' => 0, 'error_count' => 0, 'errors' => [], 'row_results' => [],
        ];
    }
}
