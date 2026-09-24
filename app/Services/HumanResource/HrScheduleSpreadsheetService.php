<?php

namespace App\Services\HumanResource;

use App\Services\Support\SimpleXlsxService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class HrScheduleSpreadsheetService
{
    private const SCHEDULE_HEADERS = [
        'work_date', 'outlet_code', 'outlet_name', 'nisj', 'employee_name',
        'assignment', 'schedule_type', 'shift_name', 'notes',
    ];

    public function __construct(private readonly SimpleXlsxService $xlsx) {}

    public function export(string $outletId, string $month): Response
    {
        [$start, $end] = $this->monthRange($month);
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name', 'type', 'timezone']);
        if (! $outlet) throw new InvalidArgumentException('Penugasan tidak ditemukan.');

        $groups = $this->activeAssignmentGroups($outletId, $start, $end);
        $employeeIds = $groups->keys()->map(fn ($id) => (string) $id)->values()->all();
        $scheduleMap = collect();
        if ($employeeIds !== []) {
            $scheduleMap = DB::table('HR_shift_schedules')
                ->where('outlet_id', $outletId)
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('work_date', [$start, $end])
                ->get()
                ->keyBy(fn ($row) => (string) $row->employee_id.'|'.substr((string) $row->work_date, 0, 10));
        }

        $rows = [self::SCHEDULE_HEADERS];
        $cursor = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);
        while ($cursor->lte($last)) {
            $date = $cursor->toDateString();
            foreach ($groups as $employeeId => $assignments) {
                $assignment = $this->assignmentForDate($assignments, $date);
                if (! $assignment) continue;
                $schedule = $scheduleMap->get((string) $employeeId.'|'.$date);
                $type = $schedule ? strtoupper((string) ($schedule->schedule_type ?? 'shift')) : 'UNMAPPED';
                if (! in_array($type, ['SHIFT', 'OFF'], true)) $type = 'UNMAPPED';

                $rows[] = [
                    $date,
                    (string) ($outlet->code ?? ''),
                    (string) ($outlet->name ?? ''),
                    (string) ($assignment->nisj ?? ''),
                    (string) ($assignment->full_name ?? ''),
                    (string) ($assignment->role_title ?? ''),
                    $type,
                    $type === 'SHIFT' ? (string) ($schedule->shift_name_snapshot ?? '') : '',
                    (string) ($schedule->notes ?? ''),
                ];
            }
            $cursor = $cursor->addDay();
        }

        $shiftRows = [['shift_name', 'start_time', 'end_time', 'penugasan', 'outlet_code', 'status']];
        DB::table('HR_shifts as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->where('s.outlet_id', $outletId)
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.is_active')
            ->orderBy('s.start_time')
            ->orderBy('s.name')
            ->get(['s.name', 's.start_time', 's.end_time', 's.is_active', 'o.name as outlet_name', 'o.code as outlet_code'])
            ->each(function ($shift) use (&$shiftRows): void {
                $shiftRows[] = [
                    (string) $shift->name,
                    $this->timeHm($shift->start_time),
                    $this->timeHm($shift->end_time),
                    (string) $shift->outlet_name,
                    (string) ($shift->outlet_code ?? ''),
                    (bool) $shift->is_active ? 'ACTIVE' : 'INACTIVE',
                ];
            });

        $guide = [
            ['No', 'Petunjuk'],
            ['1', 'Edit hanya sheet SCHEDULE. MASTER SHIFT dan PETUNJUK adalah referensi.'],
            ['2', 'schedule_type hanya boleh SHIFT, OFF, atau UNMAPPED.'],
            ['3', 'Jika schedule_type = SHIFT, isi shift_name persis seperti MASTER SHIFT. Nama shift dibuat unik oleh Iterasi 22.'],
            ['4', 'OFF menghapus shift pada tanggal tersebut dan menyimpan status libur.'],
            ['5', 'UNMAPPED menghapus mapping tanggal tersebut.'],
            ['6', 'NISJ harus milik Data Squad berstatus Active dan memiliki penugasan pada tanggal tersebut.'],
            ['7', 'Import bersifat all-or-nothing. Jika satu baris salah, tidak ada perubahan database yang disimpan.'],
            ['8', 'Mengubah employee_name/outlet_name tidak mengubah master data; keduanya hanya kolom informasi.'],
        ];

        $code = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($outlet->code ?? 'PENUGASAN')) ?: 'PENUGASAN';
        return $this->xlsx->downloadWorkbook(
            'HR_MAPPING_SCHEDULE_'.$code.'_'.str_replace('-', '', $month).'.xlsx',
            [
                ['name' => 'SCHEDULE', 'rows' => $rows],
                ['name' => 'MASTER SHIFT', 'rows' => $shiftRows],
                ['name' => 'PETUNJUK', 'rows' => $guide],
            ]
        );
    }

    public function import(
        UploadedFile $file,
        string $outletId,
        string $month,
        ?string $actorUserId,
        bool $canCreate,
        bool $canUpdate,
        bool $canDelete,
    ): array {
        [$start, $end] = $this->monthRange($month);
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name', 'type', 'timezone']);
        if (! $outlet) return $this->failed([['row' => 0, 'field' => 'outlet', 'message' => 'Penugasan tidak ditemukan.']]);

        try {
            $sheet = $this->scheduleWorksheet($this->xlsx->readWorksheets($file));
        } catch (\Throwable $e) {
            return $this->failed([['row' => 0, 'field' => 'file', 'message' => $e->getMessage()]]);
        }

        $header = $this->headerMap($sheet['rows'][0] ?? []);
        foreach (['work_date', 'outlet_code', 'nisj', 'schedule_type', 'shift_name'] as $required) {
            if (! array_key_exists($required, $header)) {
                return $this->failed([['row' => 1, 'field' => $required, 'message' => "Header {$required} wajib tersedia di sheet SCHEDULE."]]);
            }
        }

        $groups = $this->activeAssignmentGroups($outletId, $start, $end);
        $employeesByNisj = [];
        foreach ($groups as $employeeId => $assignments) {
            $first = $assignments->first();
            $nisjKey = $this->identifierKey((string) ($first->nisj ?? ''));
            if ($nisjKey === '') continue;
            if (isset($employeesByNisj[$nisjKey]) && $employeesByNisj[$nisjKey]['employee_id'] !== (string) $employeeId) {
                $employeesByNisj[$nisjKey]['ambiguous'] = true;
                continue;
            }
            $employeesByNisj[$nisjKey] = [
                'employee_id' => (string) $employeeId,
                'assignments' => $assignments,
                'ambiguous' => false,
            ];
        }

        $shiftMap = [];
        DB::table('HR_shifts')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'outlet_id', 'name', 'start_time', 'end_time'])
            ->each(function ($shift) use (&$shiftMap): void {
                $key = $this->nameKey((string) $shift->name);
                if ($key === '') return;
                if (isset($shiftMap[$key])) {
                    $shiftMap[$key]['ambiguous'] = true;
                    return;
                }
                $shiftMap[$key] = ['shift' => $shift, 'ambiguous' => false];
            });

        $errors = [];
        $prepared = [];
        $seen = [];
        $outletCodeKey = mb_strtolower(trim((string) ($outlet->code ?? '')));

        foreach (array_slice($sheet['rows'], 1) as $offset => $row) {
            $line = $offset + 2;
            if ($this->blankRow($row)) continue;

            $date = $this->normalizeDate($row[$header['work_date']] ?? '');
            $nisj = $this->identifierKey((string) ($row[$header['nisj']] ?? ''));
            $type = strtoupper(trim((string) ($row[$header['schedule_type']] ?? '')));
            $shiftName = trim((string) ($row[$header['shift_name']] ?? ''));
            $notes = array_key_exists('notes', $header) ? trim((string) ($row[$header['notes']] ?? '')) : '';
            $fileOutletCode = mb_strtolower(trim((string) ($row[$header['outlet_code']] ?? '')));

            if (! $date) $errors[] = $this->err($line, 'work_date', 'Tanggal tidak valid. Gunakan YYYY-MM-DD.');
            elseif ($date < $start || $date > $end) $errors[] = $this->err($line, 'work_date', "Tanggal harus berada pada bulan {$month}.");

            if ($outletCodeKey !== '' && $fileOutletCode !== '' && $fileOutletCode !== $outletCodeKey) {
                $errors[] = $this->err($line, 'outlet_code', 'Outlet pada baris tidak sama dengan penugasan yang sedang dipilih.');
            }
            if ($nisj === '') $errors[] = $this->err($line, 'nisj', 'NISJ wajib diisi.');
            if (! in_array($type, ['SHIFT', 'OFF', 'UNMAPPED'], true)) {
                $errors[] = $this->err($line, 'schedule_type', 'Gunakan SHIFT, OFF, atau UNMAPPED.');
            }
            if (mb_strlen($notes) > 1000) $errors[] = $this->err($line, 'notes', 'Notes maksimal 1000 karakter.');

            $employee = $employeesByNisj[$nisj] ?? null;
            if ($nisj !== '' && ! $employee) {
                $errors[] = $this->err($line, 'nisj', 'NISJ tidak ditemukan sebagai Data Squad Active dengan penugasan pada bulan terpilih.');
            } elseif ($employee && $employee['ambiguous']) {
                $errors[] = $this->err($line, 'nisj', 'NISJ terhubung ke lebih dari satu employee. Rapikan data pivot terlebih dahulu.');
            } elseif ($employee && $date && ! $this->assignmentForDate($employee['assignments'], $date)) {
                $errors[] = $this->err($line, 'work_date', 'Pegawai tidak memiliki penugasan ini pada tanggal tersebut.');
            }

            $shift = null;
            if ($type === 'SHIFT') {
                if ($shiftName === '') {
                    $errors[] = $this->err($line, 'shift_name', 'shift_name wajib jika schedule_type = SHIFT.');
                } else {
                    $match = $shiftMap[$this->nameKey($shiftName)] ?? null;
                    if (! $match) {
                        $errors[] = $this->err($line, 'shift_name', 'Nama shift tidak ditemukan pada Data Shift Active.');
                    } elseif ($match['ambiguous']) {
                        $errors[] = $this->err($line, 'shift_name', 'Nama shift ambigu/duplikat. Jalankan migration Iterasi 22 dan rapikan Data Shift.');
                    } elseif ((string) $match['shift']->outlet_id !== $outletId) {
                        $errors[] = $this->err($line, 'shift_name', 'Shift bukan milik penugasan yang dipilih.');
                    } else {
                        $shift = $match['shift'];
                    }
                }
            }

            if (! $date || ! $employee || $employee['ambiguous'] || ! in_array($type, ['SHIFT', 'OFF', 'UNMAPPED'], true)) continue;
            $key = $employee['employee_id'].'|'.$date;
            if (isset($seen[$key])) {
                $errors[] = $this->err($line, 'work_date', 'NISJ + tanggal terduplikasi dengan baris '.$seen[$key].'.');
                continue;
            }
            $seen[$key] = $line;
            $prepared[] = [
                'line' => $line,
                'employee_id' => $employee['employee_id'],
                'work_date' => $date,
                'schedule_type' => $type,
                'shift' => $shift,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        if ($prepared === [] && $errors === []) {
            $errors[] = $this->err(0, 'file', 'Sheet SCHEDULE tidak memiliki baris data.');
        }
        if ($errors !== []) return $this->failed($errors, count($prepared));

        $existingMap = DB::table('HR_shift_schedules')
            ->whereIn('employee_id', array_values(array_unique(array_column($prepared, 'employee_id'))))
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->keyBy(fn ($row) => (string) $row->employee_id.'|'.substr((string) $row->work_date, 0, 10));

        $operationErrors = [];
        foreach ($prepared as &$item) {
            $existing = $existingMap->get($item['employee_id'].'|'.$item['work_date']);
            $item['existing'] = $existing;
            if ($existing && filled($existing->outlet_id) && (string) $existing->outlet_id !== $outletId) {
                $operationErrors[] = $this->err($item['line'], 'work_date', 'Tanggal sudah memiliki mapping pada penugasan lain.');
                continue;
            }
            $item['operation'] = $this->operationFor($item, $existing);
            if ($item['operation'] === 'create' && ! $canCreate) $operationErrors[] = $this->err($item['line'], 'permission', 'Tidak memiliki hak Create Mapping Schedule.');
            if ($item['operation'] === 'update' && ! $canUpdate) $operationErrors[] = $this->err($item['line'], 'permission', 'Tidak memiliki hak Edit Mapping Schedule.');
            if ($item['operation'] === 'delete' && ! $canDelete) $operationErrors[] = $this->err($item['line'], 'permission', 'Tidak memiliki hak Delete Mapping Schedule untuk UNMAPPED.');
        }
        unset($item);
        if ($operationErrors !== []) return $this->failed($operationErrors, count($prepared));

        $timezone = $this->safeTimezone((string) ($outlet->timezone ?? 'Asia/Jakarta'));
        $result = DB::transaction(function () use ($prepared, $outlet, $outletId, $timezone, $actorUserId): array {
            $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
            foreach ($prepared as $item) {
                $operation = $item['operation'];
                $existing = $item['existing'];
                if ($operation === 'unchanged') { $stats['unchanged']++; continue; }
                if ($operation === 'delete') {
                    DB::table('HR_shift_schedules')->where('id', $existing->id)->delete();
                    $stats['deleted']++;
                    continue;
                }

                $type = strtolower($item['schedule_type']);
                $shift = $item['shift'];
                $payload = [
                    'outlet_id' => $outletId,
                    'shift_id' => $type === 'shift' ? (string) $shift->id : null,
                    'schedule_type' => $type,
                    'outlet_code_snapshot' => (string) ($outlet->code ?? ''),
                    'outlet_name_snapshot' => (string) ($outlet->name ?? '-'),
                    'outlet_timezone_snapshot' => $timezone,
                    'shift_name_snapshot' => $type === 'shift' ? (string) $shift->name : null,
                    'start_time_snapshot' => $type === 'shift' ? $shift->start_time : null,
                    'end_time_snapshot' => $type === 'shift' ? $shift->end_time : null,
                    'is_overnight_snapshot' => $type === 'shift' ? $this->isOvernight($shift->start_time, $shift->end_time) : false,
                    'assigned_by_user_id' => $actorUserId,
                    'mapping_source' => 'excel',
                    'notes' => $item['notes'],
                    'updated_at' => now(),
                ];

                if ($operation === 'update') {
                    DB::table('HR_shift_schedules')->where('id', $existing->id)->update($payload);
                    $stats['updated']++;
                } else {
                    DB::table('HR_shift_schedules')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'employee_id' => $item['employee_id'],
                        'work_date' => $item['work_date'],
                        'created_at' => now(),
                    ]);
                    $stats['inserted']++;
                }
            }
            return $stats;
        });

        return [
            'success' => true,
            ...$result,
            'created' => (int) ($result['inserted'] ?? 0),
            'processed' => count($prepared),
            'error_count' => 0,
            'errors' => [],
            'stats' => [
                'created' => (int) ($result['inserted'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'deleted' => (int) ($result['deleted'] ?? 0),
                'unchanged' => (int) ($result['unchanged'] ?? 0),
            ],
            'mode' => 'ATOMIC_UPSERT_BY_NISJ_DATE',
        ];
    }

    private function activeAssignmentGroups(string $outletId, string $start, string $end)
    {
        $query = DB::table('assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('a.outlet_id', $outletId)
            ->where(function ($q) use ($end): void { $q->whereNull('a.start_date')->orWhereDate('a.start_date', '<=', $end); })
            ->where(function ($q) use ($start): void { $q->whereNull('a.end_date')->orWhereDate('a.end_date', '>=', $start); });
        $this->applyActiveSquadScope($query);

        return $query->orderBy('e.full_name')->orderByDesc('a.is_primary')->orderByDesc('a.start_date')
            ->get([
                'a.id as assignment_id', 'a.employee_id', 'a.role_title', 'a.start_date', 'a.end_date', 'a.is_primary',
                'e.full_name', 'e.nickname', 'e.nisj', 'e.user_id',
            ])
            ->groupBy(fn ($row) => (string) $row->employee_id);
    }

    private function applyActiveSquadScope($query): void
    {
        if (! Schema::hasTable('HR_squads')) { $query->whereRaw('1 = 0'); return; }
        $hasUserId = Schema::hasColumn('HR_squads', 'user_id');
        $query->whereExists(function ($sub) use ($hasUserId): void {
            $sub->selectRaw('1')->from('HR_squads as hs')->whereNull('hs.deleted_at')
                ->whereRaw("LOWER(TRIM(COALESCE(hs.status, 'active'))) = 'active'")
                ->where(function ($link) use ($hasUserId): void {
                    if ($hasUserId) {
                        $link->where(function ($byUser): void { $byUser->whereNotNull('e.user_id')->whereColumn('hs.user_id', 'e.user_id'); });
                    }
                    $method = $hasUserId ? 'orWhereRaw' : 'whereRaw';
                    $link->{$method}("TRIM(COALESCE(e.nisj, '')) <> '' AND LOWER(TRIM(hs.nisj)) = LOWER(TRIM(e.nisj))");
                });
        });
    }

    private function assignmentForDate($assignments, string $date): ?object
    {
        return $assignments->first(function ($row) use ($date): bool {
            if ($row->start_date && substr((string) $row->start_date, 0, 10) > $date) return false;
            if ($row->end_date && substr((string) $row->end_date, 0, 10) < $date) return false;
            return true;
        });
    }

    private function operationFor(array $item, ?object $existing): string
    {
        if ($item['schedule_type'] === 'UNMAPPED') return $existing ? 'delete' : 'unchanged';
        if (! $existing) return 'create';

        $type = strtolower($item['schedule_type']);
        $shiftId = $type === 'shift' ? (string) $item['shift']->id : null;
        $same = strtolower((string) ($existing->schedule_type ?? '')) === $type
            && ((string) ($existing->shift_id ?? '') === (string) ($shiftId ?? ''))
            && (string) ($existing->notes ?? '') === (string) ($item['notes'] ?? '');
        return $same ? 'unchanged' : 'update';
    }

    private function scheduleWorksheet(array $worksheets): array
    {
        foreach ($worksheets as $sheet) {
            if (mb_strtoupper(trim((string) ($sheet['name'] ?? ''))) === 'SCHEDULE') return $sheet;
        }
        foreach ($worksheets as $sheet) {
            $map = $this->headerMap($sheet['rows'][0] ?? []);
            if (isset($map['work_date'], $map['nisj'], $map['schedule_type'])) return $sheet;
        }
        throw new InvalidArgumentException('Worksheet SCHEDULE tidak ditemukan. Gunakan file hasil Export Schedule.');
    }

    private function headerMap(array $row): array
    {
        $aliases = [
            'work_date' => ['work_date', 'tanggal', 'date'],
            'outlet_code' => ['outlet_code', 'kode outlet', 'kode penugasan'],
            'outlet_name' => ['outlet_name', 'penugasan', 'outlet'],
            'nisj' => ['nisj'],
            'employee_name' => ['employee_name', 'nama pegawai', 'nama'],
            'assignment' => ['assignment', 'posisi', 'jabatan'],
            'schedule_type' => ['schedule_type', 'type', 'tipe'],
            'shift_name' => ['shift_name', 'nama shift', 'shift'],
            'notes' => ['notes', 'catatan'],
        ];
        $normalizedAliases = [];
        foreach ($aliases as $field => $items) foreach ($items as $item) $normalizedAliases[$this->headerKey($item)] = $field;
        $map = [];
        foreach ($row as $index => $value) {
            $field = $normalizedAliases[$this->headerKey((string) $value)] ?? null;
            if ($field !== null && ! array_key_exists($field, $map)) $map[$field] = $index;
        }
        return $map;
    }

    private function headerKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return preg_replace('/[^a-z0-9]+/u', '_', $value) ?: '';
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        if (is_numeric($raw)) {
            try { return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $raw))->toDateString(); } catch (\Throwable) {}
        }
        try { return CarbonImmutable::parse($raw)->toDateString(); } catch (\Throwable) { return null; }
    }

    private function identifierKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^[0-9]+(?:\.0+)?$/', $value)) $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        elseif (preg_match('/^[0-9.]+[eE][+-]?[0-9]+$/', $value)) $value = sprintf('%.0f', (float) $value);
        return mb_strtolower(trim($value));
    }

    private function nameKey(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return mb_strtolower($value);
    }

    private function blankRow(array $row): bool
    {
        foreach ($row as $value) if (trim((string) $value) !== '') return false;
        return true;
    }

    private function monthRange(string $month): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Bulan tidak valid. Gunakan format YYYY-MM.');
        }
        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Jakarta';
    }

    private function timeHm(mixed $value): string
    {
        return substr((string) ($value ?? ''), 0, 5);
    }

    private function isOvernight(mixed $start, mixed $end): bool
    {
        $s = $this->timeHm($start); $e = $this->timeHm($end);
        return $s !== '' && $e !== '' && $e <= $s;
    }

    private function err(int $row, string $field, string $message): array
    {
        return compact('row', 'field', 'message');
    }

    private function failed(array $errors, int $processed = 0): array
    {
        return [
            'success' => false,
            'processed' => $processed,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'unchanged' => 0,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 300),
            'mode' => 'ATOMIC_UPSERT_BY_NISJ_DATE',
        ];
    }
}
