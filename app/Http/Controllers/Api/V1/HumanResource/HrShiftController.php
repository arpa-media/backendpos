<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HrShiftController extends Controller
{
    private const TABLE = 'HR_shifts';

    public function index(Request $request)
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ApiResponse::ok([
                'items' => [],
                'meta' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'last_page' => 1],
            ], 'Tabel shift belum tersedia. Jalankan migration Iterasi 01.');
        }

        $search = trim((string) $request->query('search', ''));
        $outletId = trim((string) $request->query('outlet_id', ''));
        $perPage = max(5, min(100, (int) $request->query('per_page', 10)));
        $sortBy = strtolower(trim((string) $request->query('sort_by', 'outlet')));
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortMap = [
            'outlet' => 'o.name',
            'name' => 's.name',
            'start_time' => 's.start_time',
            'end_time' => 's.end_time',
            'updated_at' => 's.updated_at',
        ];
        $sortColumn = $sortMap[$sortBy] ?? $sortMap['outlet'];

        $query = DB::table(self::TABLE.' as s')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereNull('s.deleted_at')
            ->select([
                's.id',
                's.outlet_id',
                's.name',
                's.start_time',
                's.end_time',
                's.is_active',
                's.created_at',
                's.updated_at',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.type as outlet_type',
                'o.timezone as outlet_timezone',
            ])
            ->when($outletId !== '', fn ($q) => $q->where('s.outlet_id', $outletId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('s.name', 'like', "%{$search}%")
                        ->orWhere('o.name', 'like', "%{$search}%")
                        ->orWhere('o.code', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('s.start_time')
            ->orderBy('s.name');

        $paginator = $query->paginate($perPage);

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($row) => $this->formatShift($row))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'OK');
    }

    public function storeBulk(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.name' => ['required', 'string', 'max:100'],
            'items.*.start_time' => ['required', 'date_format:H:i'],
            'items.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $seen = [];
            foreach ((array) $request->input('items', []) as $index => $item) {
                $start = (string) ($item['start_time'] ?? '');
                $end = (string) ($item['end_time'] ?? '');
                if ($start !== '' && $end !== '' && $start === $end) {
                    $validator->errors()->add("items.{$index}.end_time", 'Jam selesai tidak boleh sama dengan jam mulai.');
                }

                $nameKey = $this->shiftNameKey((string) ($item['name'] ?? ''));
                if ($nameKey !== '' && isset($seen[$nameKey])) {
                    $validator->errors()->add("items.{$index}.name", 'Nama shift tidak boleh sama dengan baris lain.');
                }
                if ($nameKey !== '' && $this->shiftNameExists($nameKey)) {
                    $validator->errors()->add("items.{$index}.name", 'Nama shift sudah digunakan. Gunakan nama shift yang unik.');
                }
                if ($nameKey !== '') $seen[$nameKey] = true;
            }
        });

        if ($validator->fails()) {
            return ApiResponse::error('Validasi Data Shift gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $outletId = (string) $request->input('outlet_id');
        $now = Carbon::now();
        $createdIds = [];

        DB::transaction(function () use ($request, $outletId, $now, &$createdIds) {
            foreach ($request->input('items') as $item) {
                $id = (string) Str::ulid();
                $createdIds[] = $id;
                DB::table(self::TABLE)->insert([
                    'id' => $id,
                    'outlet_id' => $outletId,
                    'name' => $this->cleanShiftName((string) $item['name']),
                    ...($this->supportsNameKey() ? ['name_key' => $this->shiftNameKey((string) $item['name'])] : []),
                    'start_time' => $this->databaseTime((string) $item['start_time']),
                    'end_time' => $this->databaseTime((string) $item['end_time']),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $items = $this->rowsByIds($createdIds);

        return ApiResponse::ok([
            'created' => count($createdIds),
            'items' => $items,
        ], count($createdIds).' shift berhasil ditambahkan.', 201);
    }

    public function show(string $id)
    {
        $row = $this->findRow($id);
        if (! $row) {
            return ApiResponse::error('Shift tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($this->formatShift($row), 'OK');
    }

    public function update(Request $request, string $id)
    {
        $existing = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $existing) {
            return ApiResponse::error('Shift tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $validator->after(function ($validator) use ($request, $id) {
            if ((string) $request->input('start_time') === (string) $request->input('end_time')) {
                $validator->errors()->add('end_time', 'Jam selesai tidak boleh sama dengan jam mulai.');
            }
            $nameKey = $this->shiftNameKey((string) $request->input('name'));
            if ($nameKey !== '' && $this->shiftNameExists($nameKey, $id)) {
                $validator->errors()->add('name', 'Nama shift sudah digunakan. Gunakan nama shift yang unik.');
            }
        });

        if ($validator->fails()) {
            return ApiResponse::error('Validasi Data Shift gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        DB::table(self::TABLE)->where('id', $id)->update([
            'outlet_id' => (string) $request->input('outlet_id'),
            'name' => $this->cleanShiftName((string) $request->input('name')),
            ...($this->supportsNameKey() ? ['name_key' => $this->shiftNameKey((string) $request->input('name'))] : []),
            'start_time' => $this->databaseTime((string) $request->input('start_time')),
            'end_time' => $this->databaseTime((string) $request->input('end_time')),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : (bool) $existing->is_active,
            'updated_at' => Carbon::now(),
        ]);

        return $this->show($id);
    }

    public function destroy(string $id)
    {
        $existing = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $existing) {
            return ApiResponse::error('Shift tidak ditemukan.', 'NOT_FOUND', 404);
        }

        DB::table(self::TABLE)->where('id', $id)->update([
            'is_active' => false,
            ...($this->supportsNameKey() ? ['name_key' => null] : []),
            'deleted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return ApiResponse::ok(null, 'Shift berhasil dihapus.');
    }

    private function findRow(string $id): ?object
    {
        return DB::table(self::TABLE.' as s')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->where('s.id', $id)
            ->whereNull('s.deleted_at')
            ->select([
                's.id', 's.outlet_id', 's.name', 's.start_time', 's.end_time', 's.is_active',
                's.created_at', 's.updated_at', 'o.code as outlet_code', 'o.name as outlet_name',
                'o.type as outlet_type', 'o.timezone as outlet_timezone',
            ])
            ->first();
    }

    private function rowsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table(self::TABLE.' as s')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereIn('s.id', $ids)
            ->select([
                's.id', 's.outlet_id', 's.name', 's.start_time', 's.end_time', 's.is_active',
                's.created_at', 's.updated_at', 'o.code as outlet_code', 'o.name as outlet_name',
                'o.type as outlet_type', 'o.timezone as outlet_timezone',
            ])
            ->orderBy('s.start_time')
            ->get()
            ->map(fn ($row) => $this->formatShift($row))
            ->values()
            ->all();
    }

    private function formatShift(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'outlet_id' => (string) $row->outlet_id,
            'outlet' => [
                'id' => (string) $row->outlet_id,
                'code' => (string) ($row->outlet_code ?? ''),
                'name' => (string) ($row->outlet_name ?? ''),
                'type' => (string) ($row->outlet_type ?? 'outlet'),
                'timezone' => (string) ($row->outlet_timezone ?? 'Asia/Jakarta'),
            ],
            'name' => (string) $row->name,
            'start_time' => $this->displayTime($row->start_time),
            'end_time' => $this->displayTime($row->end_time),
            'crosses_midnight' => strcmp($this->displayTime($row->end_time), $this->displayTime($row->start_time)) < 0,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function supportsNameKey(): bool
    {
        return Schema::hasColumn(self::TABLE, 'name_key');
    }

    private function cleanShiftName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        return mb_substr($name, 0, 100);
    }

    private function shiftNameKey(string $name): string
    {
        return mb_strtolower($this->cleanShiftName($name));
    }

    private function shiftNameExists(string $nameKey, ?string $ignoreId = null): bool
    {
        if ($nameKey === '') return false;

        $query = DB::table(self::TABLE)->whereNull('deleted_at');
        if ($ignoreId) $query->where('id', '<>', $ignoreId);

        if ($this->supportsNameKey()) {
            return $query->where('name_key', $nameKey)->exists();
        }

        return $query->whereRaw('LOWER(TRIM(name)) = ?', [$nameKey])->exists();
    }

    private function databaseTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('H:i:s');
    }

    private function displayTime($time): string
    {
        return substr((string) $time, 0, 5);
    }
}
