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

class HrMasterDataController extends Controller
{
    private const MASTER_TABLE = 'HR_master_data';
    private const TIER_TABLE = 'HR_salary_tiers';

    private const TYPES = [
        'chamber' => 'Data Chamber',
        'role' => 'Data Role',
        'division' => 'Data Divisi',
        'position' => 'Data Jabatan',
        'company' => 'Data PT',
    ];

    public function index(Request $request)
    {
        $type = $this->normalizeType($request->query('type'));
        if (!$type) {
            return ApiResponse::error('Tipe master data tidak valid.', 'HR_MASTER_INVALID_TYPE', 422, [
                'type' => ['Gunakan salah satu: chamber, role, division, position, company.'],
            ]);
        }

        $items = DB::table(self::MASTER_TABLE)
            ->where('type', $type)
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => $this->formatMaster($item))
            ->values();

        return ApiResponse::ok([
            'type' => $type,
            'label' => self::TYPES[$type],
            'items' => $items,
        ], 'OK');
    }

    public function store(Request $request)
    {
        $type = $this->normalizeType($request->input('type'));
        if (!$type) {
            return ApiResponse::error('Tipe master data tidak valid.', 'HR_MASTER_INVALID_TYPE', 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150', Rule::unique(self::MASTER_TABLE, 'name')->where('type', $type)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $now = Carbon::now();
        $id = DB::table(self::MASTER_TABLE)->insertGetId([
            'type' => $type,
            'name' => $this->normalizeName($request->input('name')),
            'description' => $request->input('description'),
            'is_active' => $request->boolean('is_active', true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $item = DB::table(self::MASTER_TABLE)->where('id', $id)->first();

        return ApiResponse::ok($this->formatMaster($item), 'Data berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id)
    {
        $item = DB::table(self::MASTER_TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Data tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150', Rule::unique(self::MASTER_TABLE, 'name')->where('type', $item->type)->whereNull('deleted_at')->ignore($item->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        DB::table(self::MASTER_TABLE)->where('id', $item->id)->update([
            'name' => $this->normalizeName($request->input('name')),
            'description' => $request->input('description'),
            'is_active' => $request->boolean('is_active', (bool) $item->is_active),
            'updated_at' => Carbon::now(),
        ]);

        $fresh = DB::table(self::MASTER_TABLE)->where('id', $item->id)->first();

        return ApiResponse::ok($this->formatMaster($fresh), 'Data berhasil diperbarui.');
    }

    public function destroy(string $id)
    {
        $item = DB::table(self::MASTER_TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Data tidak ditemukan.', 'NOT_FOUND', 404);
        }

        DB::table(self::MASTER_TABLE)->where('id', $item->id)->update([
            'deleted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return ApiResponse::ok(null, 'Data berhasil dihapus.');
    }

    public function salaryTierIndex(Request $request)
    {
        $items = DB::table(self::TIER_TABLE)
            ->whereNull('deleted_at')
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => $this->formatSalaryTier($item))
            ->values();

        return ApiResponse::ok(['items' => $items], 'OK');
    }

    public function salaryTierStore(Request $request)
    {
        $validator = $this->salaryTierValidator($request, null);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $now = Carbon::now();
        $payload = $this->salaryTierPayload($request);
        $deleted = DB::table(self::TIER_TABLE)
            ->where('name', $payload['name'])
            ->whereNotNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if ($deleted) {
            DB::table(self::TIER_TABLE)->where('id', $deleted->id)->update(array_merge($payload, [
                'deleted_at' => null,
                'updated_at' => $now,
            ]));
            $item = DB::table(self::TIER_TABLE)->where('id', $deleted->id)->first();
            return ApiResponse::ok($this->formatSalaryTier($item), 'Tier gaji berhasil dibuat ulang.', 201);
        }

        $id = DB::table(self::TIER_TABLE)->insertGetId(array_merge($payload, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $item = DB::table(self::TIER_TABLE)->where('id', $id)->first();

        return ApiResponse::ok($this->formatSalaryTier($item), 'Tier gaji berhasil dibuat.', 201);
    }

    public function salaryTierUpdate(Request $request, string $id)
    {
        $item = DB::table(self::TIER_TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Tier gaji tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $validator = $this->salaryTierValidator($request, $item->id);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        DB::table(self::TIER_TABLE)->where('id', $item->id)->update(array_merge($this->salaryTierPayload($request), [
            'updated_at' => Carbon::now(),
        ]));

        $fresh = DB::table(self::TIER_TABLE)->where('id', $item->id)->first();

        return ApiResponse::ok($this->formatSalaryTier($fresh), 'Tier gaji berhasil diperbarui.');
    }

    public function salaryTierDestroy(string $id)
    {
        $item = DB::table(self::TIER_TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Tier gaji tidak ditemukan.', 'NOT_FOUND', 404);
        }

        DB::table(self::TIER_TABLE)->where('id', $item->id)->update([
            'deleted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return ApiResponse::ok(null, 'Tier gaji berhasil dihapus.');
    }

    public function options()
    {
        $masters = DB::table(self::MASTER_TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $rows->map(fn ($item) => $this->formatMaster($item))->values());

        $salaryTiers = DB::table(self::TIER_TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => $this->formatSalaryTier($item))
            ->values();

        $outlets = collect();
        if (Schema::hasTable('outlets')) {
            $outlets = DB::table('outlets')
                ->select(['id', 'hr_outlet_id', 'code', 'name', 'type', 'is_active'])
                ->whereNotNull('name')
                ->orderBy('name')
                ->get()
                ->map(fn ($outlet) => [
                    'id' => (string) $outlet->id,
                    'hr_outlet_id' => $outlet->hr_outlet_id ? (string) $outlet->hr_outlet_id : null,
                    'code' => $outlet->code,
                    'name' => $outlet->name,
                    'type' => strtolower(trim((string) ($outlet->type ?: 'outlet'))),
                    'is_active' => (bool) ($outlet->is_active ?? true),
                ])
                ->values();
        }

        $knownAssignmentValues = $outlets
            ->flatMap(fn ($outlet) => array_filter([
                mb_strtolower(trim((string) $outlet['id'])),
                mb_strtolower(trim((string) ($outlet['hr_outlet_id'] ?? ''))),
                mb_strtolower(trim((string) ($outlet['code'] ?? ''))),
                mb_strtolower(trim((string) $outlet['name'])),
            ]))
            ->flip();

        if (Schema::hasTable('HR_squads')) {
            $legacyOutlets = DB::table('HR_squads')
                ->whereNull('deleted_at')
                ->whereNotNull('assignment')
                ->where('assignment', '<>', '')
                ->distinct()
                ->orderBy('assignment')
                ->pluck('assignment')
                ->filter(fn ($assignment) => ! $knownAssignmentValues->has(mb_strtolower(trim((string) $assignment))))
                ->map(fn ($assignment) => [
                    'id' => (string) $assignment,
                    'hr_outlet_id' => null,
                    'code' => null,
                    'name' => (string) $assignment,
                    'type' => 'legacy',
                    'is_active' => true,
                ]);

            $outlets = $outlets->concat($legacyOutlets)->values();
        }

        $filterOutlets = $outlets
            ->filter(fn ($outlet) => in_array($outlet['type'], ['outlet', 'headquarter', 'warehouse'], true))
            ->values();

        $latestNisj = ['onboarding' => null, 'trainee' => null];
        if (Schema::hasTable('HR_squads')) {
            $latestNisj['onboarding'] = $this->latestNisjForType('onboarding');
            $latestNisj['trainee'] = $this->latestNisjForType('trainee');
        }

        return ApiResponse::ok([
            'chambers' => $masters->get('chamber', collect())->values(),
            'roles' => $masters->get('role', collect())->values(),
            'divisions' => $masters->get('division', collect())->values(),
            'positions' => $masters->get('position', collect())->values(),
            'companies' => $masters->get('company', collect())->values(),
            'salary_tiers' => $salaryTiers,
            'outlets' => $outlets,
            'filter_outlets' => $filterOutlets,
            'user_access_roles' => Schema::hasTable('access_roles')
                ? DB::table('access_roles')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->values()
                : collect(),
            'user_access_levels' => Schema::hasTable('access_levels')
                ? DB::table('access_levels')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->values()
                : collect(),
            'latest_nisj' => $latestNisj,
        ], 'OK');
    }

    private function latestNisjForType(string $targetType): ?string
    {
        $rows = DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereNotNull('nisj')
            ->where('nisj', '<>', '')
            ->get(['nisj', 'employee_type']);

        $matches = $rows
            ->filter(function ($row) use ($targetType) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $row->employee_type));
                return str_contains($normalized, $targetType);
            })
            ->pluck('nisj')
            ->map(fn ($nisj) => trim((string) $nisj))
            ->filter()
            ->values()
            ->all();

        usort($matches, fn ($left, $right) => strnatcasecmp($right, $left));

        return $matches[0] ?? null;
    }

    private function normalizeType(?string $type): ?string
    {
        $normalized = strtolower(trim((string) $type));
        return array_key_exists($normalized, self::TYPES) ? $normalized : null;
    }

    private function normalizeName(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper((string) $name)));
    }

    private function formatMaster(object $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'name' => $item->name,
            'description' => $item->description,
            'is_active' => (bool) $item->is_active,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    private function salaryTierValidator(Request $request, ?int $ignoreId)
    {
        $unique = Rule::unique(self::TIER_TABLE, 'name')->whereNull('deleted_at');
        if ($ignoreId) $unique = $unique->ignore($ignoreId);

        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150', $unique],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'daily_salary' => ['nullable', 'numeric', 'min:0'],
            'minute_deduction' => ['nullable', 'numeric', 'min:0'],
            'hourly_overtime' => ['nullable', 'numeric', 'min:0'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'family_allowance' => ['nullable', 'numeric', 'min:0'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'cashbon' => ['nullable', 'numeric', 'min:0'],
            'other' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function salaryTierPayload(Request $request): array
    {
        return [
            'name' => $this->normalizeName($request->input('name')),
            'basic_salary' => $request->input('basic_salary', 0) ?: 0,
            'daily_salary' => $request->input('daily_salary', 0) ?: 0,
            'minute_deduction' => $request->input('minute_deduction', 0) ?: 0,
            'hourly_overtime' => $request->input('hourly_overtime', 0) ?: 0,
            'bonus' => $request->input('bonus', 0) ?: 0,
            'family_allowance' => $request->input('family_allowance', 0) ?: 0,
            'position_allowance' => $request->input('position_allowance', 0) ?: 0,
            'cashbon' => $request->input('cashbon', 0) ?: 0,
            'other' => $request->input('other', 0) ?: 0,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function formatSalaryTier(object $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'basic_salary' => (float) $item->basic_salary,
            'daily_salary' => (float) $item->daily_salary,
            'minute_deduction' => (float) $item->minute_deduction,
            'hourly_overtime' => (float) $item->hourly_overtime,
            'bonus' => (float) $item->bonus,
            'family_allowance' => (float) $item->family_allowance,
            'position_allowance' => (float) $item->position_allowance,
            'cashbon' => (float) $item->cashbon,
            'other' => (float) $item->other,
            'is_active' => (bool) $item->is_active,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
