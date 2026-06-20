<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class HrOutletController extends Controller
{
    private const TABLE = 'outlets';
    private const TYPES = ['outlet', 'headquarter', 'warehouse'];

    public function index(Request $request)
    {
        if (!Schema::hasTable(self::TABLE)) {
            return ApiResponse::ok(['items' => []], 'Tabel outlets belum tersedia.');
        }

        $type = $this->normalizeType($request->query('type'));
        $search = trim((string) $request->query('search', ''));

        $rows = DB::table(self::TABLE)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when(Schema::hasColumn(self::TABLE, 'is_active'), fn ($q) => $q->where('is_active', true))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("FIELD(type, 'outlet', 'headquarter', 'warehouse')")
            ->orderBy('name')
            ->get();

        $countsByOutletId = $this->assignmentCountsByOutletId($rows->pluck('id')->all());
        $countsByOutletName = $this->assignmentCountsByOutletName($rows);

        $items = $rows->map(function ($row) use ($countsByOutletId, $countsByOutletName) {
            $counts = $countsByOutletId[$row->id] ?? [];
            if (empty($counts)) {
                $key = $this->outletNameKey($row);
                $counts = $countsByOutletName[$key] ?? [];
            }

            return $this->formatOutlet($row, $counts);
        })->values();

        return ApiResponse::ok(['items' => $items], 'OK');
    }

    public function show(string $id)
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (!$row) return ApiResponse::error('Outlet tidak ditemukan.', 'NOT_FOUND', 404);

        $counts = $this->assignmentCountsByOutletId([$row->id])[$row->id] ?? [];
        if (empty($counts)) {
            $counts = $this->assignmentCountsByOutletName(collect([$row]))[$this->outletNameKey($row)] ?? [];
        }

        return ApiResponse::ok($this->formatOutlet($row, $counts), 'OK');
    }

    public function store(Request $request)
    {
        $validator = $this->validator($request, null);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $now = Carbon::now();
        $payload = $this->payload($request, null);
        $id = (string) Str::ulid();

        DB::table(self::TABLE)->insert(array_merge($payload, [
            'id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return $this->show($id);
    }

    public function update(Request $request, string $id)
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (!$row) return ApiResponse::error('Outlet tidak ditemukan.', 'NOT_FOUND', 404);

        $validator = $this->validator($request, $id);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        DB::table(self::TABLE)->where('id', $id)->update(array_merge($this->payload($request, $row), [
            'updated_at' => Carbon::now(),
        ]));

        return $this->show($id);
    }

    public function destroy(string $id)
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (!$row) return ApiResponse::error('Outlet tidak ditemukan.', 'NOT_FOUND', 404);

        if (Schema::hasColumn(self::TABLE, 'is_active')) {
            DB::table(self::TABLE)->where('id', $id)->update(['is_active' => false, 'updated_at' => Carbon::now()]);
        } else {
            DB::table(self::TABLE)->where('id', $id)->delete();
        }

        return ApiResponse::ok(null, 'Outlet berhasil dihapus.');
    }

    private function validator(Request $request, ?string $ignoreId)
    {
        $codeUnique = Rule::unique(self::TABLE, 'code');
        if ($ignoreId) $codeUnique = $codeUnique->ignore($ignoreId, 'id');

        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:50', $codeUnique],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(self::TYPES)],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function payload(Request $request, ?object $existing): array
    {
        $payload = [
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
            'type' => $this->normalizeType($request->input('type')) ?: 'outlet',
            'address' => $request->input('address'),
            'timezone' => $request->input('timezone') ?: 'Asia/Jakarta',
            'latitude' => $request->input('latitude') !== null && $request->input('latitude') !== '' ? $request->input('latitude') : null,
            'longitude' => $request->input('longitude') !== null && $request->input('longitude') !== '' ? $request->input('longitude') : null,
            'radius_m' => $request->input('radius_m') ?: null,
        ];

        foreach (['phone', 'is_hr_source', 'is_compatibility_stub', 'is_active'] as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) continue;
            if ($column === 'phone') $payload[$column] = $request->input('phone');
            if ($column === 'is_hr_source') $payload[$column] = $existing->is_hr_source ?? false;
            if ($column === 'is_compatibility_stub') $payload[$column] = $existing->is_compatibility_stub ?? false;
            if ($column === 'is_active') $payload[$column] = true;
        }

        return $payload;
    }

    private function normalizeType($type): ?string
    {
        $normalized = strtolower(trim((string) $type));
        return in_array($normalized, self::TYPES, true) ? $normalized : null;
    }

    private function formatOutlet(object $row, array $roleCounts = []): array
    {
        ksort($roleCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $total = array_sum(array_map('intval', $roleCounts));

        return [
            'id' => $row->id,
            'code' => $row->code ?? '',
            'name' => $row->name ?? '',
            'type' => $row->type ?? 'outlet',
            'address' => $row->address ?? '',
            'phone' => $row->phone ?? '',
            'timezone' => $row->timezone ?? 'Asia/Jakarta',
            'latitude' => $row->latitude ?? null,
            'longitude' => $row->longitude ?? null,
            'radius_m' => $row->radius_m ?? null,
            'role_counts' => $roleCounts,
            'total_squad' => $total,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function assignmentCountsByOutletId(array $ids): array
    {
        if (empty($ids) || !Schema::hasTable('assignments')) return [];

        return DB::table('assignments')
            ->select('outlet_id', 'role_title', DB::raw('COUNT(*) as total'))
            ->whereIn('outlet_id', $ids)
            ->whereNotNull('role_title')
            ->where('role_title', '<>', '')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->groupBy('outlet_id', 'role_title')
            ->get()
            ->reduce(function ($carry, $row) {
                $carry[$row->outlet_id][$this->normalizeRoleName($row->role_title)] = (int) $row->total;
                return $carry;
            }, []);
    }

    private function assignmentCountsByOutletName($outlets): array
    {
        if (!Schema::hasTable('HR_squads')) return [];

        $names = $outlets->flatMap(fn ($row) => [$row->name ?? null, $row->code ?? null])
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->unique(fn ($value) => mb_strtolower(trim((string) $value)))
            ->values();

        if ($names->isEmpty()) return [];

        $rows = DB::table('HR_squads')
            ->select('assignment', 'position_name', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereIn('assignment', $names->all())
            ->whereNotNull('position_name')
            ->where('position_name', '<>', '')
            ->groupBy('assignment', 'position_name')
            ->get();

        $outletKeyByLookup = [];
        foreach ($outlets as $outlet) {
            $key = $this->outletNameKey($outlet);
            foreach ([$outlet->name ?? null, $outlet->code ?? null] as $lookup) {
                if (trim((string) $lookup) !== '') $outletKeyByLookup[mb_strtolower(trim((string) $lookup))] = $key;
            }
        }

        return $rows->reduce(function ($carry, $row) use ($outletKeyByLookup) {
            $lookup = mb_strtolower(trim((string) $row->assignment));
            $outletKey = $outletKeyByLookup[$lookup] ?? $lookup;
            $carry[$outletKey][$this->normalizeRoleName($row->position_name)] = (int) $row->total;
            return $carry;
        }, []);
    }

    private function outletNameKey(object $row): string
    {
        return mb_strtolower(trim((string) ($row->name ?? $row->code ?? $row->id)));
    }

    private function normalizeRoleName($role): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper((string) $role))) ?: 'LAINNYA';
    }
}
