<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use ZipArchive;

class HrSquadController extends Controller
{
    private const TABLE = 'HR_squads';
    private const TIER_TABLE = 'HR_salary_tiers';

    private ?array $outletLookup = null;

    public function __construct(private readonly UserManagementService $userManagement)
    {
    }

    public function index(Request $request)
    {
        $status = strtolower((string) $request->query('status', 'active'));
        $search = trim((string) $request->query('search', ''));
        $outlet = trim((string) $request->query('outlet', ''));
        $custom = trim((string) $request->query('custom', ''));
        $perPageInput = strtolower((string) $request->query('per_page', 20));
        $perPage = $perPageInput === 'all' ? 'all' : min(max((int) $perPageInput, 1), 200);

        if ($status === 'non_squad') {
            return $this->nonSquadIndex($request, $perPage);
        }

        $query = DB::table(self::TABLE)->select($this->listColumns())->whereNull('deleted_at');

        $this->applyStatusScope($query, $status);
        
        if ($outlet !== '') {
            $this->applyOutletFilter($query, $outlet);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%")
                    ->orWhere('nisj', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role_name', 'like', "%{$search}%")
                    ->orWhere('position_name', 'like', "%{$search}%");
            });
        }
        if ($custom !== '') {
            $query->where(function ($q) use ($custom) {
                $q->where('employee_type', 'like', "%{$custom}%")
                    ->orWhere('contract_type', 'like', "%{$custom}%")
                    ->orWhere('chamber_name', 'like', "%{$custom}%")
                    ->orWhere('division_name', 'like', "%{$custom}%")
                    ->orWhere('position_name', 'like', "%{$custom}%")
                    ->orWhere('role_name', 'like', "%{$custom}%");
            });
        }

        $this->applyDetailFilters($query, $request);
        $this->applySort($query, $request);

        if ($perPage === 'all') {
            $rows = $query->get();
            return ApiResponse::ok([
                'items' => $rows->map(fn ($item) => $this->formatSquadList($item))->values(),
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 'all',
                    'total' => $rows->count(),
                    'last_page' => 1,
                ],
            ], 'OK');
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($item) => $this->formatSquadList($item))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'OK');
    }

    public function store(Request $request)
    {
        $validator = $this->validator($request, null);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $payload = $this->payload($request, null, false);
        if ($request->hasFile('photo')) {
            $payload['photo_path'] = $request->file('photo')->store('hr/squads', 'public');
        }

        $now = Carbon::now();
        $id = DB::table(self::TABLE)->insertGetId(array_merge($payload, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $item = DB::table(self::TABLE)->where('id', $id)->first();
        return ApiResponse::ok($this->formatSquad($item), 'Data squad berhasil dibuat.', 201);
    }

    public function show(string $id)
    {
        $item = DB::table(self::TABLE)->select($this->detailColumns())->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($this->formatSquad($item), 'OK');
    }

    public function update(Request $request, string $id)
    {
        $item = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $validator = $this->validator($request, (int) $item->id);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $payload = $this->payload($request, $item, false);
        if ($request->hasFile('photo')) {
            if ($item->photo_path) Storage::disk('public')->delete($item->photo_path);
            $payload['photo_path'] = $request->file('photo')->store('hr/squads', 'public');
        }

        DB::table(self::TABLE)->where('id', $item->id)->update(array_merge($payload, [
            'updated_at' => Carbon::now(),
        ]));

        $fresh = DB::table(self::TABLE)->where('id', $item->id)->first();
        return ApiResponse::ok($this->formatSquad($fresh), 'Data squad berhasil diperbarui.');
    }

    public function destroy(string $id)
    {
        $item = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$item) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        DB::table(self::TABLE)->where('id', $item->id)->update([
            'deleted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return ApiResponse::ok(null, 'Data squad berhasil dihapus.');
    }

    public function linkUser(Request $request, string $id)
    {
        $squad = $this->findSquadOrFail($id);
        if ($squad instanceof \Illuminate\Http\JsonResponse) {
            return $squad;
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', Rule::exists('users', 'id')],
        ]);

        $linkedElsewhere = DB::table(self::TABLE)
            ->where('user_id', $data['user_id'])
            ->where('id', '<>', $squad->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($linkedElsewhere) {
            return ApiResponse::error('User sudah terhubung ke Data Squad lain.', 'HR_USER_ALREADY_LINKED', 422);
        }

        DB::table(self::TABLE)->where('id', $squad->id)->update([
            'user_id' => $data['user_id'],
            'updated_at' => Carbon::now(),
        ]);

        return ApiResponse::ok(
            $this->formatSquad(DB::table(self::TABLE)->where('id', $squad->id)->first()),
            'User existing berhasil dihubungkan ke Data Squad.'
        );
    }

    public function createUser(Request $request, string $id)
    {
        $squad = $this->findSquadOrFail($id);
        if ($squad instanceof \Illuminate\Http\JsonResponse) {
            return $squad;
        }

        if (! empty($squad->user_id)) {
            return ApiResponse::error('Data Squad ini sudah memiliki user.', 'HR_SQUAD_USER_EXISTS', 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')],
            'nisj' => ['nullable', 'string', 'max:100'],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = DB::transaction(function () use ($request, $squad, $data) {
            $created = $this->userManagement->createUser($request->user(), [
                ...$data,
                'name' => trim((string) ($data['name'] ?? '')) ?: (string) $squad->full_name,
                'nisj' => trim((string) ($data['nisj'] ?? '')) ?: ($squad->nisj ?? null),
                'assignment_role_title' => $squad->position_name ?? $squad->role_name ?? null,
                'outlet_id' => $data['outlet_id'] ?? $this->resolveAssignmentOutletId($squad->assignment ?? null),
                'is_active' => true,
            ]);

            $user = $created['user'];
            $access = $user->accessAssignment()->with(['role', 'level'])->first();

            DB::table(self::TABLE)->where('id', $squad->id)->update([
                'user_id' => (string) $user->id,
                'username' => $user->username,
                'access_role' => $access?->role?->code,
                'access_level' => $access?->level?->code,
                'updated_at' => Carbon::now(),
            ]);

            return $user;
        });

        $fresh = DB::table(self::TABLE)->where('id', $squad->id)->first();

        return ApiResponse::ok([
            'squad' => $this->formatSquad($fresh),
            'user_id' => (string) $result->id,
        ], 'User baru berhasil dibuat dan dihubungkan ke Data Squad.', 201);
    }

    public function export(Request $request)
    {
        $status = strtolower((string) $request->query('status', 'active'));
        $search = trim((string) $request->query('search', ''));
        $outlet = trim((string) $request->query('outlet', ''));
        $custom = trim((string) $request->query('custom', ''));

        $query = DB::table(self::TABLE)->whereNull('deleted_at');

        $this->applyStatusScope($query, $status);
        if ($outlet !== '') {
            $query->where('assignment', 'like', "%{$outlet}%");
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%")
                    ->orWhere('nisj', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role_name', 'like', "%{$search}%")
                    ->orWhere('position_name', 'like', "%{$search}%");
            });
        }
        if ($custom !== '') {
            $query->where(function ($q) use ($custom) {
                $q->where('employee_type', 'like', "%{$custom}%")
                    ->orWhere('contract_type', 'like', "%{$custom}%")
                    ->orWhere('chamber_name', 'like', "%{$custom}%")
                    ->orWhere('division_name', 'like', "%{$custom}%")
                    ->orWhere('position_name', 'like', "%{$custom}%")
                    ->orWhere('role_name', 'like', "%{$custom}%");
            });
        }
        $this->applyDetailFilters($query, $request);
        $this->applySort($query, $request);

        $rows = $query->get();

        $exportRows = [$this->headers()];
        foreach ($rows as $row) {
            $exportRows[] = $this->rowForSpreadsheet($row);
        }

        return $this->xlsxResponse('hr_squad_export_' . now()->format('Ymd_His') . '.xlsx', $exportRows);
    }

    public function template()
    {
        return $this->templateXlsx();
    }

    public function templateXlsx()
    {
        return $this->xlsxResponse('template_import_hr_squad.xlsx', [
            $this->headers(),
            $this->sampleImportRow(),
        ]);
    }

    public function import(Request $request)
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        @ignore_user_abort(true);

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('File import tidak valid. Gunakan file XLSX dari template HR.', 'VALIDATION_ERROR', 422, [
                'success' => false,
                'inserted' => 0,
                'updated' => 0,
                'error_count' => 1,
                'errors' => [[
                    'line' => '-',
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => 'File',
                        'field' => 'file',
                        'value' => '',
                        'message' => collect($validator->errors()->get('file'))->first() ?: 'File wajib XLSX.',
                    ]],
                ]],
                'note' => 'Download ulang template XLSX lalu upload file dengan format .xlsx.',
            ]);
        }

        try {
            $rows = $this->readImportRows($request->file('file'));
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 'HR_IMPORT_READ_FAILED', 422, [
                'success' => false,
                'inserted' => 0,
                'updated' => 0,
                'error_count' => 1,
                'errors' => [[
                    'line' => '-',
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => 'File XLSX',
                        'field' => 'file',
                        'value' => '',
                        'message' => $exception->getMessage(),
                    ]],
                ]],
                'note' => 'File tidak dapat dibaca. Gunakan template XLSX terbaru dari modal import.',
            ]);
        }

        if (count($rows) < 2) {
            return ApiResponse::error('File import kosong. Minimal harus berisi header dan 1 baris data.', 'HR_IMPORT_EMPTY', 422, [
                'success' => false,
                'inserted' => 0,
                'updated' => 0,
                'error_count' => 1,
                'errors' => [[
                    'line' => 2,
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => 'Baris Data',
                        'field' => 'row',
                        'value' => '',
                        'message' => 'Tidak ada baris data setelah header.',
                    ]],
                ]],
                'note' => 'Isi minimal 1 baris data di bawah header template.',
            ]);
        }

        $expected = $this->headers();
        $header = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $expectedNormalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $expected);
        $headerMap = array_flip($header);
        $missing = [];
        foreach ($expectedNormalized as $index => $expectedName) {
            if (!array_key_exists($expectedName, $headerMap)) {
                $missing[] = $expected[$index];
            }
        }
        if ($missing) {
            return ApiResponse::error('Header import tidak sesuai template.', 'HR_IMPORT_HEADER_MISMATCH', 422, [
                'success' => false,
                'inserted' => 0,
                'updated' => 0,
                'error_count' => count($missing),
                'missing_columns' => $missing,
                'errors' => collect($missing)->map(fn ($column) => [
                    'line' => 1,
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => $column,
                        'field' => $column,
                        'value' => '',
                        'message' => 'Kolom/header tidak ditemukan di baris pertama.',
                    ]],
                ])->values()->all(),
                'note' => 'Download ulang template terbaru, jangan mengganti nama kolom/header.',
            ]);
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        $totalRows = 0;
        foreach ($rows as $row) {
            $line++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                $skipped++;
                continue;
            }
            $totalRows++;

            $data = [];
            foreach ($expected as $index => $column) {
                $normalized = $expectedNormalized[$index];
                $data[$column] = $this->normalizeImportCell($row[$headerMap[$normalized]] ?? '');
            }

            try {
                $mapped = $this->mapImportData($data);
                $existing = $this->findExistingSquadForImport($mapped);

                if ($existing) {
                    // Field user read-only: jangan sampai validasi import gagal karena nilai template berbeda,
                    // dan jangan sampai import existing mengubah username/password/access.
                    $mapped['username'] = $existing->username;
                    $mapped['password'] = $existing->password;
                    $mapped['access_role'] = $existing->access_role;
                    $mapped['access_level'] = $existing->access_level;
                } else {
                    foreach (['username', 'password', 'access_role', 'access_level'] as $userField) {
                        $mapped[$userField] = null;
                    }
                }

                $fakeRequest = new Request($mapped);
                $rowValidator = $this->validator($fakeRequest, $existing?->id ? (int) $existing->id : null, true);

                if ($rowValidator->fails()) {
                    $errors[] = $this->formatImportRowError($line, $mapped, $data, $rowValidator->errors()->toArray());
                    continue;
                }

                $now = Carbon::now();
                if ($existing) {
                    // Import Data Squad tidak boleh mengubah username/password/access role/access level existing.
                    foreach (['username', 'password', 'access_role', 'access_level'] as $readOnlyUserField) {
                        unset($mapped[$readOnlyUserField]);
                    }
                    $updatePayload = $this->filterTablePayload(array_merge($mapped, ['updated_at' => $now]));
                    DB::table(self::TABLE)->where('id', $existing->id)->update($updatePayload);
                    $updated++;
                } else {
                    $insertPayload = $this->filterTablePayload(array_merge($mapped, ['created_at' => $now, 'updated_at' => $now]));
                    DB::table(self::TABLE)->insert($insertPayload);
                    $inserted++;
                }
            } catch (\Throwable $exception) {
                $errors[] = $this->formatImportExceptionRowError($line, $data, $mapped ?? [], $exception);
                continue;
            }
        }

        $errorCount = count($errors);
        $processed = $inserted + $updated;
        $success = $errorCount === 0;
        $summary = [
            'success' => $success,
            'status' => $success ? 'success' : ($processed > 0 ? 'partial' : 'failed'),
            'total_rows' => $totalRows,
            'processed' => $processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'error_count' => $errorCount,
            'note' => 'Import XLSX selesai. Data dengan NISJ sama akan diperbarui tanpa mengubah wiring user. Data baru masuk sebagai Data Squad tanpa membuat akun user otomatis.',
        ];

        $message = $success
            ? "Import sukses. {$inserted} data tambah, {$updated} data update, 0 error."
            : "Import selesai dengan error. {$inserted} data tambah, {$updated} data update, {$errorCount} baris error.";

        return ApiResponse::ok($summary, $message);
    }



    private function applyStatusScope($query, string $status): void
    {
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }
    }

    private function applySort($query, Request $request): void
    {
        $allowed = [
            'full_name' => 'full_name',
            'nisj' => 'nisj',
            'email' => 'email',
            'role_name' => 'role_name',
            'position_name' => 'position_name',
            'assignment' => 'assignment',
            'status' => 'status',
        ];
        $sortBy = $allowed[$request->query('sort_by', 'full_name')] ?? 'full_name';
        $direction = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $direction)->orderBy('id', 'asc');
    }

    private function applyDetailFilters($query, Request $request): void
    {
        $map = [
            'employee_type' => 'employee_type',
            'contract_type' => 'contract_type',
            'division' => 'division_name',
            'position' => 'position_name',
        ];

        foreach ($map as $param => $column) {
            $value = trim((string) $request->query($param, ''));
            if ($value !== '') {
                $query->where($column, 'like', "%{$value}%");
            }
        }

        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('full_name', 'like', "%{$keyword}%")
                    ->orWhere('nickname', 'like', "%{$keyword}%")
                    ->orWhere('nisj', 'like', "%{$keyword}%")
                    ->orWhere('nik', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('whatsapp', 'like', "%{$keyword}%");
            });
        }
    }

    private function validator(Request $request, ?int $ignoreId, bool $importMode = false)
    {
        $nisjUnique = Rule::unique(self::TABLE, 'nisj')->whereNull('deleted_at');
        $emailUnique = Rule::unique(self::TABLE, 'email')->whereNull('deleted_at');
        $usernameUnique = Rule::unique(self::TABLE, 'username')->whereNull('deleted_at');
        if ($ignoreId) {
            $nisjUnique = $nisjUnique->ignore($ignoreId);
            $emailUnique = $emailUnique->ignore($ignoreId);
            $usernameUnique = $usernameUnique->ignore($ignoreId);
        }

        return Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:180'],
            'nickname' => ['nullable', 'string', 'max:80'],
            'nik' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:30'],
            'religion' => ['nullable', 'string', 'max:60'],
            'education' => ['nullable', 'string', 'max:80'],
            'marital_status' => ['nullable', 'string', 'max:80'],
            'children_count' => ['nullable', 'integer', 'min:0'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180', $emailUnique],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'nisj' => ['nullable', 'string', 'max:80', $nisjUnique],
            'employee_type' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'bpjs_number' => ['nullable', 'string', 'max:100'],
            'bpjstk_number' => ['nullable', 'string', 'max:100'],
            'faskes' => ['nullable', 'string', 'max:150'],
            'ppi_status' => ['nullable', 'boolean'],
            'photo' => $importMode ? ['nullable'] : ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'contract_type' => ['nullable', 'string', 'max:80'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'assignment' => ['nullable', 'string', 'max:180'],
            'chamber_name' => ['nullable', 'string', 'max:150'],
            'division_name' => ['nullable', 'string', 'max:150'],
            'position_name' => ['nullable', 'string', 'max:150'],
            'salary_tier_id' => ['nullable', 'integer', Rule::exists(self::TIER_TABLE, 'id')->whereNull('deleted_at')],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'daily_salary' => ['nullable', 'numeric', 'min:0'],
            'minute_deduction' => ['nullable', 'numeric', 'min:0'],
            'hourly_overtime' => ['nullable', 'numeric', 'min:0'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'family_allowance' => ['nullable', 'numeric', 'min:0'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'cashbon' => ['nullable', 'numeric', 'min:0'],
            'other' => ['nullable', 'numeric', 'min:0'],
            'username' => ['nullable', 'string', 'max:100', $usernameUnique],
            'password' => ['nullable', 'string', 'max:190'],
            'role_name' => ['nullable', 'string', 'max:150'],
            'access_role' => ['nullable', 'string', 'max:150'],
            'access_level' => ['nullable', 'string', 'max:150'],
            'leave_quota' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function payload(Request $request, ?object $existing = null, bool $importMode = false): array
    {
        $tier = null;
        if ($request->filled('salary_tier_id')) {
            $tier = DB::table(self::TIER_TABLE)->where('id', $request->input('salary_tier_id'))->whereNull('deleted_at')->first();
        }

        $value = fn ($key, $fallback = null) => $request->input($key, $fallback);
        $money = fn ($key) => $request->input($key, $tier?->{$key} ?? 0) ?: 0;

        return [
            'full_name' => $value('full_name'),
            'nickname' => $value('nickname'),
            'nik' => $value('nik'),
            'address' => $value('address'),
            'birth_place' => $value('birth_place'),
            'birth_date' => $value('birth_date') ?: null,
            'gender' => $value('gender'),
            'religion' => $value('religion'),
            'education' => $value('education'),
            'marital_status' => $value('marital_status'),
            'children_count' => (int) ($value('children_count', 0) ?: 0),
            'whatsapp' => $value('whatsapp'),
            'email' => $value('email'),
            'status' => strtolower((string) $value('status', 'active')) === 'inactive' ? 'inactive' : 'active',
            'nisj' => $value('nisj'),
            'employee_type' => $value('employee_type'),
            'bank_name' => $value('bank_name'),
            'bank_account' => $value('bank_account'),
            'bpjs_number' => $value('bpjs_number'),
            'bpjstk_number' => $value('bpjstk_number'),
            'faskes' => $value('faskes'),
            'ppi_status' => filter_var($value('ppi_status', false), FILTER_VALIDATE_BOOLEAN),
            'contract_type' => $this->normalizeContractType($value('contract_type')),
            'contract_start_date' => $value('contract_start_date') ?: null,
            'contract_end_date' => $value('contract_end_date') ?: null,
            'assignment' => $value('assignment'),
            'chamber_name' => $value('chamber_name'),
            'division_name' => $value('division_name'),
            'position_name' => $value('position_name'),
            'salary_tier_id' => $value('salary_tier_id') ?: null,
            'salary_tier_name' => $tier?->name ?: $value('salary_tier_name'),
            'basic_salary' => $money('basic_salary'),
            'daily_salary' => $money('daily_salary'),
            'minute_deduction' => $money('minute_deduction'),
            'hourly_overtime' => $money('hourly_overtime'),
            'bonus' => $money('bonus'),
            'family_allowance' => $money('family_allowance'),
            'position_allowance' => $money('position_allowance'),
            'cashbon' => $money('cashbon'),
            'other' => $money('other'),
            // Data Squad dan user sekarang memiliki lifecycle terpisah.
            // Record baru tidak otomatis membuat kredensial; wiring dilakukan dari tab Data User.
            'username' => $existing?->username,
            'password' => $existing?->password,
            'role_name' => $this->normalizeUserRole($value('role_name')),
            'access_role' => $existing?->access_role,
            'access_level' => $existing?->access_level,
            'leave_quota' => (int) ($value('leave_quota', 3) ?: 3),
        ];
    }

    private function listColumns(): array
    {
        $columns = [
            'id', 'user_id', 'full_name', 'nickname', 'nisj', 'email', 'status', 'employee_type', 'assignment',
            'division_name', 'position_name', 'role_name', 'access_role', 'access_level', 'photo_path',
            'contract_type', 'contract_start_date', 'contract_end_date', 'leave_quota', 'updated_at',
        ];

        return array_values(array_filter($columns, fn ($column) => Schema::hasColumn(self::TABLE, $column)));
    }

    private function detailColumns(): array
    {
        return array_values(array_filter(Schema::getColumnListing(self::TABLE), fn ($column) => $column !== 'deleted_at'));
    }

    private function formatSquadList(object $item): array
    {
        $data = collect((array) $item)->except(['deleted_at', 'password', 'password_plain_encrypted'])->all();
        $data['password'] = '';
        $data['password_is_decryptable'] = false;
        $data['assignment_name'] = $this->resolveAssignmentName($item->assignment ?? null);
        $data['has_squad'] = true;
        $data['has_user'] = ! empty($item->user_id);
        return $data;
    }

    private function formatSquad(object $item): array
    {
        $data = collect((array) $item)->except(['deleted_at', 'password', 'password_plain_encrypted'])->all();
        $data['password'] = '';
        $data['password_is_decryptable'] = false;
        $data['assignment_name'] = $this->resolveAssignmentName($item->assignment ?? null);
        $data['has_squad'] = true;
        $data['has_user'] = ! empty($item->user_id);
        $data['user'] = $this->serializeUser($item->user_id ?? null);
        $data['user_candidate'] = empty($item->user_id) ? $this->findUserCandidate($item) : null;
        return $data;
    }

    private function nonSquadIndex(Request $request, int|string $perPage)
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn(self::TABLE, 'user_id')) {
            return ApiResponse::ok([
                'items' => [],
                'pagination' => ['current_page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
            ], 'OK');
        }

        $query = DB::table('users as users')
            ->leftJoin(self::TABLE.' as squads', function ($join) {
                $join->on('squads.user_id', '=', 'users.id')->whereNull('squads.deleted_at');
            })
            ->leftJoin('outlets as outlets', 'outlets.id', '=', 'users.outlet_id')
            ->leftJoin('user_access_assignments as user_access', 'user_access.user_id', '=', 'users.id')
            ->leftJoin('access_roles as access_roles', 'access_roles.id', '=', 'user_access.access_role_id')
            ->leftJoin('access_levels as access_levels', 'access_levels.id', '=', 'user_access.access_level_id')
            ->whereNull('squads.id')
            ->select([
                'users.id as user_id',
                'users.name as full_name',
                'users.nisj',
                'users.username',
                'users.email',
                'users.is_active',
                'users.outlet_id as assignment',
                'outlets.name as assignment_name',
                'outlets.type as assignment_type',
                'access_roles.code as role_name',
                'access_roles.code as access_role',
                'access_levels.code as access_level',
                'users.updated_at',
            ]);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.nisj', 'like', "%{$search}%")
                    ->orWhere('users.username', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('access_roles.code', 'like', "%{$search}%");
            });
        }

        $outlet = trim((string) $request->query('outlet', ''));
        if ($outlet !== '') {
            $query->where(function ($inner) use ($outlet) {
                $inner->where('users.outlet_id', $outlet)
                    ->orWhere('outlets.name', $outlet)
                    ->orWhere('outlets.code', $outlet)
                    ->orWhere('outlets.hr_outlet_id', $outlet);
            });
        }

        $direction = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'full_name' => 'users.name',
            'nisj' => 'users.nisj',
            'email' => 'users.email',
            'role_name' => 'access_roles.code',
            'assignment' => 'outlets.name',
            'status' => 'users.is_active',
        ];
        $query->orderBy($sortColumns[$request->query('sort_by', 'full_name')] ?? 'users.name', $direction)
            ->orderBy('users.id');

        $format = fn ($item) => [
            'id' => 'user:'.(string) $item->user_id,
            'user_id' => (string) $item->user_id,
            'full_name' => $item->full_name,
            'nickname' => null,
            'nisj' => $item->nisj,
            'username' => $item->username,
            'email' => $item->email,
            'status' => (bool) $item->is_active ? 'active' : 'inactive',
            'assignment' => $item->assignment,
            'assignment_name' => $item->assignment_name,
            'assignment_type' => $item->assignment_type,
            'role_name' => $item->role_name,
            'access_role' => $item->access_role,
            'access_level' => $item->access_level,
            'updated_at' => $item->updated_at,
            'has_squad' => false,
            'has_user' => true,
            'source_kind' => 'user_without_squad',
        ];

        if ($perPage === 'all') {
            $rows = $query->get();
            return ApiResponse::ok([
                'items' => $rows->map($format)->values(),
                'pagination' => ['current_page' => 1, 'per_page' => 'all', 'total' => $rows->count(), 'last_page' => 1],
            ], 'OK');
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map($format)->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'OK');
    }

    private function applyOutletFilter($query, string $outlet): void
    {
        $candidates = [$outlet];
        if (Schema::hasTable('outlets')) {
            $matched = DB::table('outlets')
                ->where(function ($inner) use ($outlet) {
                    $inner->where('id', $outlet)
                        ->orWhere('name', $outlet)
                        ->orWhere('code', $outlet)
                        ->orWhere('hr_outlet_id', $outlet);
                })
                ->first();

            if ($matched) {
                $candidates = array_values(array_unique(array_filter([
                    (string) $matched->id,
                    (string) ($matched->hr_outlet_id ?? ''),
                    (string) ($matched->code ?? ''),
                    (string) $matched->name,
                ])));
            }
        }

        $query->where(function ($inner) use ($candidates) {
            foreach ($candidates as $candidate) {
                $inner->orWhere('assignment', $candidate);
            }
        });
    }

    private function resolveAssignmentName($assignment): ?string
    {
        $value = trim((string) $assignment);
        if ($value === '') {
            return null;
        }

        $lookup = $this->outletLookup();
        return $lookup[mb_strtolower($value)] ?? $value;
    }

    private function resolveAssignmentOutletId($assignment): ?string
    {
        $value = mb_strtolower(trim((string) $assignment));
        if ($value === '' || ! Schema::hasTable('outlets')) {
            return null;
        }

        $outlet = DB::table('outlets')
            ->where(function ($query) use ($value) {
                $query->whereRaw('LOWER(TRIM(`id`)) = ?', [$value])
                    ->orWhereRaw('LOWER(TRIM(`name`)) = ?', [$value])
                    ->orWhereRaw('LOWER(TRIM(`code`)) = ?', [$value])
                    ->orWhereRaw('LOWER(TRIM(`hr_outlet_id`)) = ?', [$value]);
            })
            ->first();

        return $outlet ? (string) $outlet->id : null;
    }

    private function outletLookup(): array
    {
        if ($this->outletLookup !== null) {
            return $this->outletLookup;
        }

        $this->outletLookup = [];
        if (! Schema::hasTable('outlets')) {
            return $this->outletLookup;
        }

        foreach (DB::table('outlets')->get(['id', 'hr_outlet_id', 'code', 'name']) as $outlet) {
            foreach ([$outlet->id, $outlet->hr_outlet_id, $outlet->code, $outlet->name] as $identity) {
                $key = mb_strtolower(trim((string) $identity));
                if ($key !== '') {
                    $this->outletLookup[$key] = (string) $outlet->name;
                }
            }
        }

        return $this->outletLookup;
    }

    private function serializeUser($userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()
            ->with(['outlet', 'accessAssignment.role', 'accessAssignment.level'])
            ->find($userId);

        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'nisj' => $user->nisj,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'outlet' => $user->outlet ? [
                'id' => (string) $user->outlet->id,
                'name' => $user->outlet->name,
                'type' => $user->outlet->type,
            ] : null,
            'access_role' => $user->accessAssignment?->role?->code,
            'access_level' => $user->accessAssignment?->level?->code,
        ];
    }

    private function findUserCandidate(object $squad): ?array
    {
        $identities = collect([
            'nisj' => $squad->nisj ?? null,
            'username' => $squad->username ?? null,
            'email' => $squad->email ?? null,
        ])->map(fn ($value) => mb_strtolower(trim((string) $value)))->filter();

        if ($identities->isEmpty()) {
            return null;
        }

        $candidate = User::query()
            ->where(function ($query) use ($identities) {
                foreach ($identities as $column => $value) {
                    if (Schema::hasColumn('users', $column)) {
                        $query->orWhereRaw("LOWER(TRIM(`{$column}`)) = ?", [$value]);
                    }
                }
            })
            ->whereNotIn('id', DB::table(self::TABLE)->whereNull('deleted_at')->whereNotNull('user_id')->pluck('user_id'))
            ->first();

        return $candidate ? [
            'id' => (string) $candidate->id,
            'name' => $candidate->name,
            'nisj' => $candidate->nisj,
            'username' => $candidate->username,
            'email' => $candidate->email,
        ] : null;
    }

    private function findSquadOrFail(string $id): object
    {
        $squad = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $squad) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return $squad;
    }

    private function sampleImportRow(): array
    {
        return [
            '10012500001', '3573xxxxxxxxxxxx', 'CONTOH SQUAD', 'CONTOH', 'L', 'MALANG', '1998-01-31',
            'Islam', 'Alamat lengkap', '62812xxxx', 'SMA', 'Belum Menikah', '0',
            'official', 'active', 'TIER 1', now()->toDateString(), '',
            'Outlet A', '50000', '104', '6250', '0', '0', '0',
            '', 'contoh@email.com', 'SQUAD',
        ];
    }

    private function readImportRows($file): array
    {
        return $this->readXlsxRows($file->getRealPath());
    }

    private function readXlsxRows(string $path): array
    {
        $entries = $this->readXlsxPackage($path);

        $sharedStrings = [];
        $sharedXml = $entries['xl/sharedStrings.xml'] ?? false;
        if ($sharedXml !== false) {
            $xml = @simplexml_load_string($sharedXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $text .= (string) $run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? false;
        if ($sheetXml === false) {
            throw new InvalidArgumentException('Worksheet pertama tidak ditemukan di file XLSX.');
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData)) {
            throw new InvalidArgumentException('Worksheet XLSX tidak dapat dibaca.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $cellRef = (string) $cell['r'];
                $columnIndex = $this->xlsxColumnIndex($cellRef);
                $type = (string) $cell['t'];
                $value = '';

                if ($type === 'inlineStr') {
                    $value = isset($cell->is->t) ? (string) $cell->is->t : '';
                } elseif ($type === 's') {
                    $valueIndex = (int) ($cell->v ?? 0);
                    $value = $sharedStrings[$valueIndex] ?? '';
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }

                $row[$columnIndex] = $value;
            }
            if ($row) {
                ksort($row);
                $max = max(array_keys($row));
                $rows[] = array_map(fn ($index) => $row[$index] ?? '', range(0, $max));
            }
        }

        return $rows;
    }


    private function readXlsxPackage(string $path): array
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new InvalidArgumentException('File XLSX tidak dapat dibuka. Pastikan file berasal dari template HR dan tidak corrupt.');
            }

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false) {
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        $entries[$name] = $content;
                    }
                }
            }
            $zip->close();

            return $entries;
        }

        $binary = @file_get_contents($path);
        if ($binary === false) {
            throw new InvalidArgumentException('File XLSX tidak dapat dibaca.');
        }

        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) {
            throw new InvalidArgumentException('File XLSX tidak valid. Struktur ZIP tidak ditemukan.');
        }

        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        $total = (int) $eocd['totalEntries'];

        for ($i = 0; $i < $total; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (($header['sig'] ?? null) !== 0x02014b50) {
                throw new InvalidArgumentException('Central directory XLSX tidak valid.');
            }

            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];

            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (($local['sig'] ?? null) !== 0x04034b50) {
                continue;
            }

            $dataStart = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $dataStart, $header['compressedSize']);

            if ((int) $header['method'] === 0) {
                $entries[$name] = $compressed;
            } elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) {
                    throw new InvalidArgumentException('Data XLSX terkompresi tidak dapat dibaca. Pastikan ekstensi zlib PHP aktif.');
                }
                $entries[$name] = $inflated;
            }
        }

        return $entries;
    }

    private function xlsxColumnIndex(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function xlsxResponse(string $filename, array $rows)
    {
        $binary = $this->buildSimpleXlsx($rows);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function buildSimpleXlsx(array $rows): string
    {
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>POS Human Resource</dc:creator><cp:lastModifiedBy>POS Human Resource</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . now()->toISOString() . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . now()->toISOString() . '</dcterms:modified></cp:coreProperties>',
            'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>POS Human Resource</Application></Properties>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="HR Squad" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/worksheets/sheet1.xml' => $this->buildWorksheetXml($rows),
        ];

        return $this->buildZip($files);
    }

    private function buildWorksheetXml(array $rows): string
    {
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->xlsxColumnName($columnIndex + 1) . ($rowIndex + 1);
                $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $safe . '</t></is></c>';
            }
            $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>';
    }

    private function buildZip(array $files): string
    {
        $data = '';
        $centralDirectory = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = crc32($content);
            $size = strlen($content);
            $nameLength = strlen($name);

            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0) . $name;
            $data .= $localHeader . $content;

            $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($localHeader) + $size;
        }

        return $data . $centralDirectory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($centralDirectory), strlen($data), 0);
    }

    private function xlsxColumnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function headers(): array
    {
        // Format mengikuti HR.zip / dbHR employees agar file export/template dari HR lama
        // bisa langsung dipakai sebagai format upload devHR di POS.
        return [
            'nisj', 'nik', 'full_name', 'nickname', 'gender', 'birth_place', 'birth_date',
            'religion', 'address', 'phone', 'education', 'marital_status', 'anak',
            'employment_type', 'employment_status', 'tier', 'join_date', 'resign_date',
            'assignment_id', 'basic_salary', 'basic_cut', 'basic_ovt', 'bonus1', 'bonus2', 'bonus3',
            'notes', 'email', 'role',
        ];
    }

    private function rowForSpreadsheet(object $row): array
    {
        return [
            $row->nisj,
            $row->nik,
            $row->full_name,
            $row->nickname,
            $this->genderToHrLegacy($row->gender ?? ''),
            $row->birth_place,
            $row->birth_date,
            $row->religion,
            $row->address,
            $row->whatsapp,
            $row->education,
            $row->marital_status,
            $row->children_count,
            $this->employeeTypeToHrLegacy($row->employee_type ?? ''),
            strtolower((string) ($row->status ?? 'active')) === 'active' ? 'active' : 'inactive',
            $row->salary_tier_name,
            $row->contract_start_date,
            $row->contract_end_date,
            $row->assignment,
            $row->basic_salary,
            $row->minute_deduction,
            $row->hourly_overtime,
            $row->bonus,
            $row->family_allowance,
            $row->position_allowance,
            '',
            $row->email,
            $row->role_name,
        ];
    }

    private function mapImportData(array $data): array
    {
        $num = fn ($value) => $this->normalizeImportNumber($value);
        $text = fn ($value) => $this->normalizeHrLegacyNullableValue($value);
        $nisj = $this->normalizeImportIdentity($data['nisj'] ?? null);
        $role = $text($data['role'] ?? null);
        $resignDate = $this->normalizeImportDate($data['resign_date'] ?? null);
        if ($resignDate === '1970-01-01') $resignDate = null;
        $email = strtolower((string) $text($data['email'] ?? null));

        return [
            'full_name' => $text($data['full_name'] ?? null),
            'nickname' => $text($data['nickname'] ?? null),
            'nik' => $text($data['nik'] ?? null),
            'address' => $text($data['address'] ?? null),
            'birth_place' => $text($data['birth_place'] ?? null),
            'birth_date' => $this->normalizeImportDate($data['birth_date'] ?? null),
            'gender' => $this->normalizeHrLegacyGender($data['gender'] ?? null),
            'religion' => $text($data['religion'] ?? null),
            'education' => $text($data['education'] ?? null),
            'marital_status' => $text($data['marital_status'] ?? null),
            'children_count' => $this->normalizeImportInteger($data['anak'] ?? 0),
            'whatsapp' => $text($data['phone'] ?? null),
            'email' => $email !== '' ? $email : null,
            'status' => $this->normalizeHrLegacyStatus($data['employment_status'] ?? 'active'),
            'nisj' => $nisj,
            'employee_type' => $this->normalizeHrLegacyEmployeeType($data['employment_type'] ?? null),
            'bank_name' => null,
            'bank_account' => null,
            'bpjs_number' => null,
            'bpjstk_number' => null,
            'faskes' => null,
            'ppi_status' => false,
            'contract_type' => 'SPT',
            'contract_start_date' => $this->normalizeImportDate($data['join_date'] ?? null),
            'contract_end_date' => $resignDate,
            'assignment' => $text($data['assignment_id'] ?? null),
            'chamber_name' => null,
            'division_name' => null,
            'position_name' => null,
            'salary_tier_name' => $text($data['tier'] ?? null),
            'basic_salary' => $num($data['basic_salary'] ?? 0),
            'daily_salary' => 0,
            'minute_deduction' => $num($data['basic_cut'] ?? 0),
            'hourly_overtime' => $num($data['basic_ovt'] ?? 0),
            'bonus' => $num($data['bonus1'] ?? 0),
            'family_allowance' => $num($data['bonus2'] ?? 0),
            'position_allowance' => $num($data['bonus3'] ?? 0),
            'cashbon' => 0,
            'other' => 0,
            'username' => $this->defaultUsername($nisj, $nisj),
            'password' => null,
            'role_name' => $this->normalizeUserRole($role),
            'access_role' => $this->defaultAccessRole(null, $role),
            'access_level' => $this->defaultAccessLevel(null, $role),
            'leave_quota' => 3,
        ];
    }


    private function genderToHrLegacy($value): string
    {
        $gender = strtolower(trim((string) $value));
        return match ($gender) {
            'male', 'm', 'l', 'laki-laki', 'laki laki', 'pria' => 'Laki-laki',
            'female', 'f', 'p', 'perempuan', 'wanita' => 'Perempuan',
            default => trim((string) $value),
        };
    }

    private function employeeTypeToHrLegacy($value): string
    {
        $type = strtoupper(trim((string) $value));
        return match ($type) {
            'TRAINEE', 'TRAINING', 'MAGANG' => 'Trainee',
            'OFFICIAL', 'TETAP', 'KARYAWAN' => 'Official',
            default => trim((string) $value),
        };
    }

    private function normalizeHrLegacyNullableValue($value): ?string
    {
        $text = $this->normalizeImportCell($value);
        $lower = strtolower($text);

        // Data hasil migrasi HR lama sering memakai angka 38 sebagai placeholder NULL.
        // Placeholder ini tidak boleh masuk sebagai tanggal 1900-02-06, email "38", tier "38", dll.
        if ($text === '' || in_array($lower, ['38', 'null', 'nil', 'n/a', 'na', '-', '\n'], true)) {
            return null;
        }

        return $text;
    }

    private function normalizeImportNumber($value): float
    {
        $text = $this->normalizeHrLegacyNullableValue($value);
        if ($text === null) return 0;

        $normalized = str_replace(',', '.', $text);
        return is_numeric($normalized) ? max(0, (float) $normalized) : 0;
    }

    private function normalizeImportInteger($value): int
    {
        $text = $this->normalizeHrLegacyNullableValue($value);
        if ($text === null) return 0;

        $normalized = str_replace(',', '.', $text);
        return is_numeric($normalized) ? max(0, (int) floor((float) $normalized)) : 0;
    }

    private function normalizeHrLegacyGender($value): ?string
    {
        $normalized = $this->normalizeHrLegacyNullableValue($value);
        if ($normalized === null) return null;
        $gender = strtolower($normalized);

        return match ($gender) {
            'l', 'lk', 'laki', 'laki-laki', 'laki laki', 'male', 'm', 'man', 'pria' => 'Laki-Laki',
            'p', 'pr', 'perempuan', 'female', 'f', 'woman', 'wanita' => 'Perempuan',
            default => trim((string) $value),
        };
    }

    private function normalizeHrLegacyStatus($value): string
    {
        $normalized = $this->normalizeHrLegacyNullableValue($value);
        if ($normalized === null) return 'active';
        $status = strtolower($normalized);

        return in_array($status, ['inactive', 'nonactive', 'non-active', 'non active', 'resign', 'resigned', 'keluar', 'tidak aktif'], true)
            ? 'inactive'
            : 'active';
    }

    private function normalizeHrLegacyEmployeeType($value): ?string
    {
        $normalized = $this->normalizeHrLegacyNullableValue($value);
        if ($normalized === null) return null;
        $type = strtolower($normalized);

        return match ($type) {
            'trainee', 'training', 'magang' => 'TRAINEE',
            'official', 'tetap', 'karyawan' => 'OFFICIAL',
            default => strtoupper($normalized),
        };
    }

    private function normalizeImportDate($value): ?string
    {
        $raw = $this->normalizeHrLegacyNullableValue($value);
        if ($raw === null) return null;

        // Excel sering menyimpan tanggal hasil edit sebagai serial number.
        // Nomor kecil seperti 38 pada data HR lama adalah placeholder NULL, bukan 1900-02-06.
        if (is_numeric($raw)) {
            $serial = (float) $raw;
            if ($serial < 20000) return null;
            try {
                return Carbon::create(1899, 12, 30)->addDays((int) floor($serial))->toDateString();
            } catch (\Throwable $exception) {
                return $raw;
            }
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'];
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
                if ($date) return $date->toDateString();
            } catch (\Throwable $exception) {
                // Coba format berikutnya.
            }
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable $exception) {
            return $raw;
        }
    }

    private function normalizeImportCell($value): string
    {
        if ($value instanceof \DateTimeInterface) return Carbon::instance($value)->toDateString();
        $text = trim((string) $value);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function findExistingSquadForImport(array $mapped): ?object
    {
        $candidates = [];
        foreach (['nisj', 'username', 'email'] as $field) {
            $value = $this->normalizeImportIdentity($mapped[$field] ?? '');
            if ($value !== '') $candidates[$field] = $value;
        }

        if (!$candidates) return null;

        $select = array_values(array_filter(['id', 'nisj', 'username', 'email', 'password', 'access_role', 'access_level'], fn ($column) => Schema::hasColumn(self::TABLE, $column)));
        $rows = DB::table(self::TABLE)
            ->select($select)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($candidates) {
                foreach ($candidates as $field => $value) {
                    if (!Schema::hasColumn(self::TABLE, $field)) continue;
                    $q->orWhere($field, $value);
                    if (in_array($field, ['nisj', 'username'], true)) {
                        $q->orWhereRaw('REPLACE(REPLACE(TRIM(' . $field . '), " ", ""), "-", "") = ?', [$this->compactIdentity($value)]);
                    }
                }
            })
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) return null;

        foreach (['nisj', 'username', 'email'] as $priority) {
            if (!isset($candidates[$priority])) continue;
            $needle = $priority === 'email' ? strtolower($candidates[$priority]) : $this->compactIdentity($candidates[$priority]);
            foreach ($rows as $row) {
                $haystack = $priority === 'email'
                    ? strtolower($this->normalizeImportIdentity($row->{$priority} ?? ''))
                    : $this->compactIdentity($row->{$priority} ?? '');
                if ($needle !== '' && $needle === $haystack) return $row;
            }
        }

        return $rows->first();
    }

    private function normalizeImportIdentity($value): string
    {
        $value = $this->normalizeImportCell($value);
        if ($value === '') return '';

        // Excel bisa menyimpan NISJ/username angka sebagai 12345.0 atau scientific notation.
        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        } elseif (preg_match('/^\d+(?:\.\d+)?E\+?\d+$/i', $value)) {
            $expanded = number_format((float) $value, 0, '', '');
            if ($expanded !== '0') $value = $expanded;
        }

        return trim($value);
    }

    private function compactIdentity($value): string
    {
        $value = $this->normalizeImportIdentity($value);
        return preg_replace('/[\s\-]+/', '', $value) ?? $value;
    }

    private function filterTablePayload(array $payload): array
    {
        static $columns = null;
        if ($columns === null) {
            $columns = array_flip(Schema::getColumnListing(self::TABLE));
        }
        return collect($payload)
            ->filter(fn ($value, $key) => isset($columns[$key]))
            ->all();
    }

    private function importFieldLabels(): array
    {
        return [
            'full_name' => 'full_name',
            'nickname' => 'nickname',
            'nik' => 'nik',
            'address' => 'address',
            'birth_place' => 'birth_place',
            'birth_date' => 'birth_date',
            'gender' => 'gender',
            'religion' => 'religion',
            'education' => 'education',
            'marital_status' => 'marital_status',
            'children_count' => 'anak',
            'whatsapp' => 'phone',
            'email' => 'email',
            'status' => 'employment_status',
            'nisj' => 'nisj',
            'employee_type' => 'employment_type',
            'contract_start_date' => 'join_date',
            'contract_end_date' => 'resign_date',
            'assignment' => 'assignment_id',
            'salary_tier_name' => 'tier',
            'basic_salary' => 'basic_salary',
            'minute_deduction' => 'basic_cut',
            'hourly_overtime' => 'basic_ovt',
            'bonus' => 'bonus1',
            'family_allowance' => 'bonus2',
            'position_allowance' => 'bonus3',
            'role_name' => 'role',
        ];
    }

    private function formatImportExceptionRowError(int $line, array $rawData, array $mapped, \Throwable $exception): array
    {
        return [
            'line' => $line,
            'row_number' => $line,
            'name' => $mapped['full_name'] ?? ($rawData['full_name'] ?? ''),
            'nisj' => $mapped['nisj'] ?? ($rawData['nisj'] ?? ''),
            'details' => [[
                'column' => 'System',
                'field' => 'exception',
                'value' => '',
                'message' => $exception->getMessage(),
            ], [
                'column' => 'Baris XLSX',
                'field' => 'row',
                'value' => collect($rawData)->map(fn ($value, $key) => $key . '=' . (is_scalar($value) ? (string) $value : ''))->implode('; '),
                'message' => 'Baris ini gagal diproses, tetapi import dilanjutkan ke baris berikutnya.',
            ]],
            'error_text' => 'System: ' . $exception->getMessage(),
        ];
    }

    private function formatImportRowError(int $line, array $mapped, array $rawData, array $validationErrors): array
    {
        $labels = $this->importFieldLabels();
        $details = [];

        foreach ($validationErrors as $field => $messages) {
            $column = $labels[$field] ?? $field;
            $value = $rawData[$column] ?? ($mapped[$field] ?? '');
            foreach ((array) $messages as $message) {
                $details[] = [
                    'column' => $column,
                    'field' => $field,
                    'value' => is_scalar($value) ? (string) $value : '',
                    'message' => $message,
                ];
            }
        }

        return [
            'line' => $line,
            'row_number' => $line,
            'name' => $mapped['full_name'] ?? '',
            'nisj' => $mapped['nisj'] ?? '',
            'details' => $details,
            'error_text' => collect($details)->map(fn ($item) => ($item['column'] ?? '-') . ': ' . ($item['message'] ?? 'Error'))->implode(' | '),
        ];
    }

    private function normalizeContractType($value): ?string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === 'TETAPI') $value = 'TETAP';
        return $value ?: null;
    }

    private function normalizeUserRole($value): string
    {
        $role = strtoupper(trim((string) $value));
        return $role ?: 'SQUAD';
    }

    private function defaultUsername($username, $nisj): ?string
    {
        $username = trim((string) $username);
        if ($username !== '') return $username;
        $nisj = trim((string) $nisj);
        return $nisj !== '' ? $nisj : null;
    }

    private function defaultAccessRole($accessRole, $role): string
    {
        $accessRole = strtoupper(trim((string) $accessRole));
        if ($accessRole !== '') return $accessRole;
        return $this->normalizeUserRole($role);
    }

    private function defaultAccessLevel($accessLevel, $role): string
    {
        $accessLevel = strtoupper(trim((string) $accessLevel));
        if ($accessLevel !== '') return $accessLevel;
        return in_array($this->normalizeUserRole($role), ['ADMIN', 'OBSERVER', 'STAKEHOLDER'], true) ? 'BACKOFFICE' : 'OUTLET';
    }

}
