<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\User;
use App\Services\HrSquadUserWiringService;
use App\Services\HumanResource\HrSquadLifecycleService;
use App\Services\Support\SimpleXlsxService;
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

    public function __construct(
        private readonly UserManagementService $userManagement,
        private readonly HrSquadUserWiringService $squadWiring,
        private readonly SimpleXlsxService $xlsx,
        private readonly HrSquadLifecycleService $lifecycle,
    ) {
    }

    public function index(Request $request)
    {
        $status = strtolower((string) $request->query('status', 'active'));
        $search = trim((string) $request->query('search', ''));
        $outlet = trim((string) $request->query('outlet', ''));
        $custom = trim((string) $request->query('custom', ''));
        $perPageInput = strtolower((string) $request->query('per_page', 20));
        $perPage = $perPageInput === 'all' ? 'all' : min(max((int) $perPageInput, 1), 200);

        // Self-healing untuk legacy user: seluruh user operasional yang memiliki NISJ
        // dimaterialisasi sebagai Data Squad sebelum daftar Active/Inactive/Non-Squad dibaca.
        // Stakeholder, Observer, dan user tanpa NISJ tidak dibuatkan Squad otomatis.
        $this->squadWiring->reconcileOperationalUsers();

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
                'counts' => $this->statusCounts(),
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
            'counts' => $this->statusCounts(),
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
        if ($request->filled('source_user_id')) {
            return $this->completeSquadFromUser($request);
        }

        $nisj = $this->squadWiring->normalizeNisj($request->input('nisj'));
        $archived = $nisj !== ''
            ? DB::table(self::TABLE)->whereNotNull('deleted_at')->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($nisj)])->first()
            : null;
        $validator = $this->validator($request, $archived?->id ? (int) $archived->id : null);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $createUser = $request->boolean('create_user', true);
        if ($nisj === '') {
            return ApiResponse::error('NISJ wajib diisi untuk Data Squad baru.', 'VALIDATION_ERROR', 422, [
                'nisj' => ['NISJ adalah pivot utama antara Data Squad dan Data User.'],
            ]);
        }

        $existingUser = $this->squadWiring->findUserByNisj($nisj);
        if ($createUser && ! $existingUser) {
            $userValidator = $this->validateProvisionUserInput($request);
            if ($userValidator->fails()) {
                return ApiResponse::error('Validasi Data User gagal.', 'VALIDATION_ERROR', 422, $userValidator->errors()->toArray());
            }
        }

        $result = DB::transaction(function () use ($request, $createUser, $existingUser, $archived) {
            $payload = $this->payload($request, null, false);
            if ($request->hasFile('photo')) {
                $payload['photo_path'] = $request->file('photo')->store('hr/squads', 'public');
            }

            $now = Carbon::now();
            if ($archived) {
                $id = (int) $archived->id;
                DB::table(self::TABLE)->where('id', $id)->update(array_merge($payload, [
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]));
            } else {
                $id = DB::table(self::TABLE)->insertGetId(array_merge($payload, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
            $squad = DB::table(self::TABLE)->where('id', $id)->first();

            $provisioning = ['status' => 'squad_only', 'user_created' => false, 'user_linked' => false];
            if ($existingUser) {
                $this->squadWiring->wireExistingUserToSquad($existingUser, $id);
                $provisioning = [
                    'status' => 'existing_user_linked',
                    'user_created' => false,
                    'user_linked' => true,
                    'user_id' => (string) $existingUser->id,
                    'user_unchanged' => true,
                ];
            } elseif ($createUser) {
                $provisioning = $this->provisionUserForSquad($request->user(), $squad, $request->all());
            }

            return [
                'squad' => DB::table(self::TABLE)->where('id', $id)->first(),
                'provisioning' => $provisioning,
            ];
        });

        return ApiResponse::ok([
            'squad' => $this->formatSquad($result['squad']),
            'user_provisioning' => $result['provisioning'],
            'restored_from_soft_delete' => (bool) $archived,
        ], $archived ? 'Data squad soft-delete berhasil dipulihkan.' : 'Data squad berhasil dibuat.', 201);
    }

    public function show(string $id)
    {
        if (str_starts_with($id, 'user:')) {
            $userId = substr($id, 5);
            $user = User::query()
                ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
                ->find($userId);

            if (! $user) {
                return ApiResponse::error('Data user non-squad tidak ditemukan.', 'NOT_FOUND', 404);
            }

            return ApiResponse::ok($this->formatNonSquadUser($user), 'OK');
        }

        $item = DB::table(self::TABLE)->select($this->detailColumns())->where('id', $id)->whereNull('deleted_at')->first();
        if (! $item) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($this->formatSquad($item), 'OK');
    }

    private function completeSquadFromUser(Request $request)
    {
        $sourceValidator = Validator::make($request->all(), [
            'source_user_id' => ['required', 'string', Rule::exists('users', 'id')],
        ], [], [
            'source_user_id' => 'Data User sumber',
        ]);

        if ($sourceValidator->fails()) {
            return ApiResponse::error(
                'Data User sumber tidak valid.',
                'HR_COMPLETE_SQUAD_SOURCE_INVALID',
                422,
                $sourceValidator->errors()->toArray()
            );
        }

        $user = User::query()
            ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
            ->find((string) $request->input('source_user_id'));

        if (! $user) {
            return ApiResponse::error('Data User sumber tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $user->outlet;
        $nisj = $this->squadWiring->normalizeNisj($user->nisj ?: $employee?->nisj);

        if ($nisj === '') {
            return ApiResponse::error(
                'Data Squad tidak dapat dilengkapi karena Data User belum memiliki NISJ.',
                'HR_COMPLETE_SQUAD_NISJ_REQUIRED',
                422,
                [
                    'nisj' => ['Isi NISJ pada User Management terlebih dahulu. NISJ adalah pivot utama Data User dan Data Squad.'],
                    'source_user_id' => ['User ditemukan, tetapi NISJ pada users maupun employees kosong.'],
                ]
            );
        }

        $existingActive = $this->squadWiring->findSquadByNisj($nisj);
        if ($existingActive) {
            $this->squadWiring->wireExistingUserToSquad($user, $existingActive->id);

            return ApiResponse::ok([
                'squad' => $this->formatSquad(DB::table(self::TABLE)->where('id', $existingActive->id)->first()),
                'already_existed' => true,
                'warnings' => [],
            ], 'Data Squad dengan NISJ yang sama sudah ada dan berhasil dihubungkan ke Data User.');
        }

        $archived = DB::table(self::TABLE)
            ->whereNotNull('deleted_at')
            ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($nisj)])
            ->first();

        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? 'SQUAD')));
        $levelCode = strtoupper(trim((string) ($user->accessAssignment?->level?->code ?? '')));
        $defaults = [
            'full_name' => trim((string) ($employee?->full_name ?: $user->name ?: $nisj)),
            'nickname' => $employee?->nickname,
            'email' => $user->email,
            'status' => (bool) $user->is_active ? 'active' : 'inactive',
            'nisj' => $nisj,
            'assignment' => $outlet?->id ? (string) $outlet->id : null,
            'position_name' => $assignment?->role_title,
            'contract_start_date' => optional($assignment?->start_date)->toDateString(),
            'contract_end_date' => optional($assignment?->end_date)->toDateString(),
            'role_name' => $roleCode !== '' ? $roleCode : 'SQUAD',
            'access_role' => $roleCode !== '' ? $roleCode : null,
            'access_level' => $levelCode !== '' ? $levelCode : null,
            'leave_quota' => 3,
        ];

        $allowed = array_flip(array_merge($this->detailColumns(), [
            'full_name', 'nickname', 'nik', 'address', 'birth_place', 'birth_date', 'gender', 'religion',
            'education', 'marital_status', 'children_count', 'whatsapp', 'email', 'status', 'nisj',
            'employee_type', 'bank_name', 'bank_account', 'bpjs_number', 'bpjstk_number', 'faskes',
            'ppi_status', 'contract_type', 'contract_start_date', 'contract_end_date', 'assignment',
            'chamber_name', 'division_name', 'position_name', 'salary_tier_id', 'salary_tier_name',
            'basic_salary', 'daily_salary', 'minute_deduction', 'hourly_overtime', 'bonus',
            'family_allowance', 'position_allowance', 'cashbon', 'other', 'role_name', 'leave_quota',
        ]));

        $submitted = collect($request->all())
            ->filter(fn ($value, $key) => isset($allowed[$key]))
            ->all();
        $input = array_merge($defaults, $submitted);

        // Identitas pivot tidak boleh diganti dari modal Lengkapi Squad.
        $input['nisj'] = $nisj;
        $input['full_name'] = trim((string) ($input['full_name'] ?? '')) ?: $defaults['full_name'];
        $input['status'] = strtolower((string) ($input['status'] ?? $defaults['status'])) === 'inactive' ? 'inactive' : 'active';

        if (empty($input['salary_tier_id']) || ! DB::table(self::TIER_TABLE)->where('id', $input['salary_tier_id'])->whereNull('deleted_at')->exists()) {
            $input['salary_tier_id'] = null;
        }

        $warnings = [];
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '') {
            $emailUsed = DB::table(self::TABLE)
                ->whereRaw('LOWER(TRIM(`email`)) = ?', [$email])
                ->when($archived, fn ($query) => $query->where('id', '<>', $archived->id))
                ->exists();
            if ($emailUsed) {
                $warnings[] = "Email {$email} sudah dipakai Data Squad lain sehingga field email Squad dikosongkan. Data User tidak diubah.";
                $input['email'] = null;
            }
        } else {
            $input['email'] = null;
        }

        $normalizedRequest = new Request($input);
        $validator = $this->validator($normalizedRequest, $archived?->id ? (int) $archived->id : null);
        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi Lengkapi Data Squad gagal. Periksa detail setiap field.',
                'HR_COMPLETE_SQUAD_VALIDATION_FAILED',
                422,
                array_merge($validator->errors()->toArray(), [
                    '_context' => [
                        'source_user_id' => (string) $user->id,
                        'nisj' => $nisj,
                        'role_code' => $roleCode,
                    ],
                ])
            );
        }

        $result = DB::transaction(function () use ($normalizedRequest, $archived, $user) {
            $payload = $this->payload($normalizedRequest, $archived, false);
            $now = Carbon::now();

            if ($archived) {
                $id = (int) $archived->id;
                DB::table(self::TABLE)->where('id', $id)->update(array_merge($payload, [
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]));
            } else {
                $id = DB::table(self::TABLE)->insertGetId(array_merge($payload, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            $wired = $this->squadWiring->wireExistingUserToSquad($user, $id);
            if (! $wired) {
                throw new InvalidArgumentException('Data Squad berhasil disimpan tetapi wiring user_id gagal. Pastikan NISJ User dan Squad sama.');
            }

            return DB::table(self::TABLE)->where('id', $id)->first();
        });

        return ApiResponse::ok([
            'squad' => $this->formatSquad($result),
            'already_existed' => false,
            'restored_from_soft_delete' => (bool) $archived,
            'warnings' => $warnings,
        ], $archived ? 'Data Squad berhasil dipulihkan dan dilengkapi dari Data User.' : 'Data Squad berhasil dilengkapi dari Data User.', 201);
    }

    public function update(Request $request, string $id)
    {
        $item = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $item) {
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

        DB::transaction(function () use ($item, $payload) {
            DB::table(self::TABLE)->where('id', $item->id)->update(array_merge($payload, [
                'updated_at' => Carbon::now(),
            ]));
            // Update Squad tidak mengubah Data User. Hanya reference teknis dipasang ulang berdasarkan NISJ.
            $this->squadWiring->wireSquadByNisj($item->id);
        });

        $fresh = DB::table(self::TABLE)->where('id', $item->id)->first();
        return ApiResponse::ok($this->formatSquad($fresh), 'Data squad berhasil diperbarui. Data User existing tidak diubah.');
    }

    public function destroy(Request $request, string $id)
    {
        $item = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();
        if (! $item) {
            return ApiResponse::error('Data squad tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $linkedUser = $this->findUserForSquad($item);
        $result = $this->lifecycle->purge($item, $linkedUser, $request->user());

        if (filled($item->photo_path ?? null)) {
            Storage::disk('public')->delete((string) $item->photo_path);
        }

        return ApiResponse::ok($result, $result['user_delete_mode'] === 'retired_tombstone_external_fk'
            ? 'Data Squad dan seluruh data HR terkait berhasil dipurge. Akun User Management dihapus dari data aktif dan dianonimkan karena masih direferensikan transaksi non-HR.'
            : 'Data Squad, akun User Management, kontrak, history, mapping schedule, dan seluruh data HR terkait berhasil dihapus.');
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
        $user = User::query()->with(['employee', 'accessAssignment.role'])->findOrFail($data['user_id']);

        $squadNisj = mb_strtolower($this->squadWiring->normalizeNisj($squad->nisj ?? null));
        $userNisj = mb_strtolower($this->squadWiring->normalizeNisj($user->nisj ?: $user->employee?->nisj));
        if ($squadNisj === '' || $userNisj === '' || $squadNisj !== $userNisj) {
            return ApiResponse::error(
                'User hanya dapat dihubungkan jika NISJ Data User sama persis dengan NISJ Data Squad.',
                'HR_NISJ_PIVOT_MISMATCH',
                422,
                ['nisj' => ['NISJ Data Squad dan Data User harus sama.']]
            );
        }

        $wired = $this->squadWiring->wireExistingUserToSquad($user, $squad->id);
        if (! $wired) {
            return ApiResponse::error('Wiring NISJ gagal dipasang.', 'HR_NISJ_WIRING_FAILED', 422);
        }

        return ApiResponse::ok(
            $this->formatSquad(DB::table(self::TABLE)->where('id', $squad->id)->first()),
            'User existing berhasil dihubungkan melalui pivot NISJ tanpa mengubah Data User.'
        );
    }

    public function createUser(Request $request, string $id)
    {
        $squad = $this->findSquadOrFail($id);
        if ($squad instanceof \Illuminate\Http\JsonResponse) {
            return $squad;
        }

        $nisj = $this->squadWiring->normalizeNisj($squad->nisj ?? null);
        if ($nisj === '') {
            return ApiResponse::error('NISJ Data Squad wajib diisi sebelum membuat Data User.', 'HR_SQUAD_NISJ_REQUIRED', 422);
        }

        $existingUser = $this->squadWiring->findUserByNisj($nisj);
        if ($existingUser) {
            $this->squadWiring->wireExistingUserToSquad($existingUser, $squad->id);
            return ApiResponse::ok([
                'squad' => $this->formatSquad(DB::table(self::TABLE)->where('id', $squad->id)->first()),
                'user_id' => (string) $existingUser->id,
                'user_created' => false,
                'user_unchanged' => true,
            ], 'Data User dengan NISJ yang sama sudah ada dan berhasil dihubungkan tanpa perubahan.');
        }

        $validator = $this->validateProvisionUserInput($request, false);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi Data User gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $result = DB::transaction(fn () => $this->provisionUserForSquad($request->user(), $squad, $request->all()));
        $fresh = DB::table(self::TABLE)->where('id', $squad->id)->first();

        return ApiResponse::ok([
            'squad' => $this->formatSquad($fresh),
            'user_id' => $result['user_id'] ?? null,
            'user_created' => (bool) ($result['user_created'] ?? false),
            'default_password_used' => (bool) ($result['default_password_used'] ?? false),
        ], 'User baru berhasil dibuat dan dihubungkan melalui pivot NISJ.', 201);
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
                'unchanged' => 0,
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
                'unchanged' => 0,
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
                'unchanged' => 0,
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

        $rawHeader = array_shift($rows);
        $headerResolution = $this->resolveImportHeaderMap($rawHeader);
        if ($headerResolution['missing'] || $headerResolution['duplicates']) {
            $headerErrors = [];
            foreach ($headerResolution['missing'] as $column) {
                $headerErrors[] = [
                    'line' => 1,
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => $column,
                        'field' => $column,
                        'value' => '',
                        'message' => 'Kolom wajib tidak ditemukan. Minimal wajib ada nisj dan full_name.',
                    ]],
                ];
            }
            foreach ($headerResolution['duplicates'] as $duplicate) {
                $headerErrors[] = [
                    'line' => 1,
                    'name' => '-',
                    'nisj' => '-',
                    'details' => [[
                        'column' => $duplicate['canonical'],
                        'field' => $duplicate['canonical'],
                        'value' => implode(', ', $duplicate['headers']),
                        'message' => 'Lebih dari satu header mengarah ke field yang sama. Hapus salah satu kolom duplikat.',
                    ]],
                ];
            }

            return ApiResponse::error('Header import tidak valid.', 'HR_IMPORT_HEADER_MISMATCH', 422, [
                'success' => false,
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'error_count' => count($headerErrors),
                'missing_columns' => $headerResolution['missing'],
                'duplicate_columns' => $headerResolution['duplicates'],
                'errors' => $headerErrors,
                'note' => 'Gunakan template terbaru. File template legacy tetap didukung selama memiliki kolom nisj dan full_name.',
            ]);
        }

        $allImportRows = array_values($rows);
        $sourceRowCount = count($allImportRows);
        $workbookTotalRows = count(array_filter($allImportRows, fn ($row) => count(array_filter($row, fn ($value) => trim((string) $value) !== '')) > 0));
        $chunkedImport = $request->boolean('chunked');
        $chunkOffset = $chunkedImport ? max(0, (int) $request->input('chunk_offset', 0)) : 0;
        $chunkSize = $chunkedImport ? min(50, max(1, (int) $request->input('chunk_size', 25))) : max(1, $sourceRowCount);
        $duplicateNisjLineMap = $this->duplicateImportNisjLineMap($allImportRows, $headerResolution['map']['nisj'] ?? null);
        if ($chunkedImport) {
            $rows = array_slice($allImportRows, $chunkOffset, $chunkSize);
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $restored = 0;
        $usersCreated = 0;
        $usersLinked = 0;
        $emailFallbacks = 0;
        $skipped = 0;
        $errors = [];
        $rowResults = [];
        $seenNisj = [];
        $line = $chunkedImport ? ($chunkOffset + 1) : 1;
        $totalRows = 0;

        foreach ($rows as $row) {
            $line++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                $skipped++;
                continue;
            }
            $totalRows++;

            $data = [];
            foreach ($headerResolution['map'] as $canonical => $index) {
                $data[$canonical] = $this->normalizeImportCell($row[$index] ?? '');
            }

            $mapped = [];
            try {
                $nisj = $this->normalizeImportIdentity($data['nisj'] ?? '');
                $nisjKey = mb_strtolower($nisj);
                if (isset($duplicateNisjLineMap[$line])) {
                    $errors[] = [
                        'line' => $line,
                        'row_number' => $line,
                        'name' => $data['full_name'] ?? '',
                        'nisj' => $nisj,
                        'details' => [[
                            'column' => 'nisj',
                            'field' => 'nisj',
                            'value' => $nisj,
                            'message' => 'NISJ duplikat di file yang sama. Pertama kali ditemukan pada baris '.$duplicateNisjLineMap[$line].'.',
                        ]],
                        'error_text' => 'nisj: NISJ duplikat di file yang sama.',
                    ];
                    continue;
                }
                if ($nisjKey !== '' && isset($seenNisj[$nisjKey])) {
                    $errors[] = [
                        'line' => $line,
                        'row_number' => $line,
                        'name' => $data['full_name'] ?? '',
                        'nisj' => $nisj,
                        'details' => [[
                            'column' => 'nisj',
                            'field' => 'nisj',
                            'value' => $nisj,
                            'message' => 'NISJ duplikat di file yang sama. Pertama kali ditemukan pada baris '.$seenNisj[$nisjKey].'.',
                        ]],
                        'error_text' => 'nisj: NISJ duplikat di file yang sama.',
                    ];
                    continue;
                }
                if ($nisjKey !== '') {
                    $seenNisj[$nisjKey] = $line;
                }

                $existing = $this->findExistingSquadForImport(['nisj' => $nisj]);
                $mapped = $this->mapImportData($data, $existing !== null);

                $emailResolution = $this->resolveImportEmailAvailability(
                    $mapped['email'] ?? null,
                    $nisj,
                    $existing?->id ? (int) $existing->id : null,
                );
                if ($emailResolution['changed']) {
                    $mapped['email'] = $emailResolution['email'];
                }

                $validationPayload = $existing
                    ? array_merge((array) $existing, $mapped)
                    : $mapped;
                foreach (['username', 'password', 'access_role', 'access_level'] as $readOnlyUserField) {
                    $validationPayload[$readOnlyUserField] = $existing?->{$readOnlyUserField} ?? null;
                }
                // Excel menggunakan salary_tier_name; ID tier lama tidak boleh membuat import gagal
                // bila master tier tersebut sudah dihapus atau berubah.
                $validationPayload['salary_tier_id'] = null;

                $fakeRequest = new Request($validationPayload);
                $rowValidator = $this->validator($fakeRequest, $existing?->id ? (int) $existing->id : null, true);
                if ($rowValidator->fails()) {
                    $errors[] = $this->formatImportRowError($line, $validationPayload, $data, $rowValidator->errors()->toArray());
                    continue;
                }

                $rowResult = DB::transaction(function () use ($request, $mapped, $existing) {
                    $now = Carbon::now();
                    if ($existing) {
                        foreach (['id', 'user_id', 'username', 'password', 'access_role', 'access_level', 'created_at', 'updated_at', 'deleted_at'] as $readOnlyField) {
                            unset($mapped[$readOnlyField]);
                        }

                        $updatePayload = $this->filterTablePayload($mapped);
                        $changes = $this->detectImportChanges($existing, $updatePayload);
                        $wasArchived = ! empty($existing->deleted_at);

                        if ($changes || $wasArchived) {
                            DB::table(self::TABLE)->where('id', $existing->id)->update(array_merge($updatePayload, [
                                'deleted_at' => null,
                                'updated_at' => $now,
                            ]));
                        }

                        // Import Data Squad tidak pernah mengubah Data User existing.
                        $matchingUser = $this->squadWiring->findUserByNisj($existing->nisj ?? ($mapped['nisj'] ?? null));
                        if ($matchingUser) {
                            $this->squadWiring->wireExistingUserToSquad($matchingUser, $existing->id);
                        } else {
                            $this->squadWiring->wireSquadByNisj($existing->id);
                        }

                        return [
                            'kind' => ($changes || $wasArchived) ? 'updated' : 'unchanged',
                            'restored' => $wasArchived,
                            'changed_fields' => array_keys($changes),
                            'user_created' => false,
                            'user_linked' => $matchingUser !== null,
                            'squad_id' => (int) $existing->id,
                        ];
                    }

                    $insertPayload = $this->filterTablePayload(array_merge($mapped, [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                    $squadId = DB::table(self::TABLE)->insertGetId($insertPayload);
                    $squad = DB::table(self::TABLE)->where('id', $squadId)->first();
                    $matchingUser = $this->squadWiring->findUserByNisj($mapped['nisj'] ?? null);

                    if ($matchingUser) {
                        $this->squadWiring->wireExistingUserToSquad($matchingUser, $squadId);
                        return [
                            'kind' => 'inserted',
                            'restored' => false,
                            'changed_fields' => array_keys($insertPayload),
                            'user_created' => false,
                            'user_linked' => true,
                            'squad_id' => (int) $squadId,
                        ];
                    }

                    $provisioning = $this->provisionUserForSquad($request->user(), $squad, [
                        'name' => $mapped['full_name'] ?? null,
                        'email' => $mapped['email'] ?? null,
                    ]);

                    return [
                        'kind' => 'inserted',
                        'restored' => false,
                        'changed_fields' => array_keys($insertPayload),
                        'user_created' => (bool) ($provisioning['user_created'] ?? false),
                        'user_linked' => (bool) ($provisioning['user_linked'] ?? false),
                        'squad_id' => (int) $squadId,
                    ];
                });

                match ($rowResult['kind']) {
                    'inserted' => $inserted++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };
                if ($rowResult['restored']) $restored++;
                if ($rowResult['user_created']) $usersCreated++;
                if ($rowResult['user_linked']) $usersLinked++;
                if ($emailResolution['changed'] ?? false) $emailFallbacks++;

                $rowResults[] = [
                    'line' => $line,
                    'nisj' => $mapped['nisj'] ?? $nisj,
                    'name' => $mapped['full_name'] ?? ($data['full_name'] ?? ''),
                    'action' => $rowResult['kind'],
                    'restored' => (bool) $rowResult['restored'],
                    'changed_fields' => $rowResult['changed_fields'],
                    'squad_id' => $rowResult['squad_id'],
                    'email_fallback_applied' => (bool) ($emailResolution['changed'] ?? false),
                    'original_email' => $emailResolution['original'] ?? null,
                    'final_email' => $emailResolution['email'] ?? ($mapped['email'] ?? null),
                ];
            } catch (\Throwable $exception) {
                $errors[] = $this->formatImportExceptionRowError($line, $data, $mapped, $exception);
            }
        }

        $errorCount = count($errors);
        $processed = $inserted + $updated + $unchanged;
        $success = $errorCount === 0;
        $summary = [
            'success' => $success,
            'status' => $success ? 'success' : ($processed > 0 ? 'partial' : 'failed'),
            'total_rows' => $totalRows,
            'processed' => $processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'restored' => $restored,
            'users_created' => $usersCreated,
            'users_linked' => $usersLinked,
            'email_fallbacks' => $emailFallbacks,
            'skipped' => $skipped,
            'errors' => $errors,
            'error_count' => $errorCount,
            'row_results' => $rowResults,
            'ignored_headers' => $headerResolution['unsupported'],
            'workbook_total_rows' => $workbookTotalRows,
            'chunk' => [
                'enabled' => $chunkedImport,
                'offset' => $chunkOffset,
                'size' => $chunkSize,
                'source_rows' => $sourceRowCount,
                'next_offset' => min($sourceRowCount, $chunkOffset + count($rows)),
                'has_more' => $chunkedImport && ($chunkOffset + count($rows) < $sourceRowCount),
                'percent' => $sourceRowCount > 0 ? round(min(100, (($chunkOffset + count($rows)) / $sourceRowCount) * 100), 2) : 100,
            ],
            'note' => 'Upsert berdasarkan NISJ: data baru ditambah, data existing hanya mengubah field yang tersedia di Excel dan benar-benar berbeda, baris tanpa perubahan tidak ditulis ulang. Jika email sudah dipakai Data Squad/User lain, import otomatis mengganti email menjadi format username@gmail.com yang unik. Data User existing tidak pernah dioverwrite.',
        ];

        $message = $success
            ? "Import sukses. {$inserted} data baru, {$updated} data berubah, {$unchanged} tanpa perubahan, {$usersCreated} user dibuat, {$emailFallbacks} email otomatis disesuaikan, 0 error."
            : "Import parsial. {$inserted} data baru, {$updated} data berubah, {$unchanged} tanpa perubahan, {$emailFallbacks} email otomatis disesuaikan, {$errorCount} baris gagal. Buka detail error untuk melihat baris, kolom, nilai, dan penyebab.";

        return ApiResponse::ok($summary, $message);
    }


    private function applyStatusScope($query, string $status): void
    {
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
            $this->applyOperationalSquadScope($query);
        }
    }

    private function applyOperationalSquadScope($query): void
    {
        foreach (['role_name', 'access_role'] as $roleColumn) {
            if (! Schema::hasColumn(self::TABLE, $roleColumn)) continue;
            $query->where(function ($roleScope) use ($roleColumn) {
                $roleScope->whereNull($roleColumn)
                    ->orWhereRaw("UPPER(TRIM(COALESCE(`{$roleColumn}`, ''))) NOT IN ('STAKEHOLDER', 'OBSERVER')");
            });
        }

        if (
            Schema::hasColumn(self::TABLE, 'user_id')
            && Schema::hasTable('user_access_assignments')
            && Schema::hasTable('access_roles')
        ) {
            $query->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('user_access_assignments as hr_i09_uaa')
                    ->join('access_roles as hr_i09_ar', 'hr_i09_ar.id', '=', 'hr_i09_uaa.access_role_id')
                    ->whereColumn('hr_i09_uaa.user_id', self::TABLE.'.user_id')
                    ->whereIn('hr_i09_ar.code', ['STAKEHOLDER', 'OBSERVER']);
            });
        }
    }

    private function statusCounts(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ['active' => 0, 'inactive' => 0, 'non_squad' => 0];
        }

        $base = DB::table(self::TABLE)->whereNull('deleted_at');
        $active = clone $base;
        $active->where('status', 'active');
        $this->applyOperationalSquadScope($active);

        $inactive = clone $base;
        $inactive->where('status', 'inactive');
        $this->applyOperationalSquadScope($inactive);

        $nonSquad = Schema::hasTable('users')
            ? $this->buildNonSquadUserQuery()->count()
            : 0;

        return [
            'active' => (int) $active->count(),
            'inactive' => (int) $inactive->count(),
            'non_squad' => (int) $nonSquad,
        ];
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

        if ($request->boolean('exact_duplicate_name')) {
            $query->whereNotNull('full_name')
                ->whereRaw("TRIM(`HR_squads`.`full_name`) <> ''")
                ->whereExists(function ($duplicate): void {
                    $duplicate->selectRaw('1')
                        ->from(self::TABLE.' as exact_duplicate_squad')
                        ->whereNull('exact_duplicate_squad.deleted_at')
                        ->whereColumn('exact_duplicate_squad.id', '<>', self::TABLE.'.id')
                        ->whereRaw('BINARY TRIM(`exact_duplicate_squad`.`full_name`) = BINARY TRIM(`HR_squads`.`full_name`)');
                });
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
        $nisjUnique = Rule::unique(self::TABLE, 'nisj');
        $emailUnique = Rule::unique(self::TABLE, 'email');
        $usernameUnique = Rule::unique(self::TABLE, 'username');
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
            'nisj' => [($importMode || $ignoreId === null) ? 'required' : 'nullable', 'string', 'max:32', $nisjUnique],
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
            // NISJ adalah pivot utama. Field kredensial di tabel legacy hanya dipertahankan untuk kompatibilitas;
            // pembuatan/update Data Squad tidak pernah mengubah kredensial Data User existing.
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
            'id', 'user_id', 'full_name', 'nickname', 'nisj', 'email', 'birth_date', 'status', 'employee_type', 'assignment',
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
        $user = $this->findUserForSquad($item);
        $data['has_squad'] = true;
        $data['has_user'] = $user !== null;
        $data['user_id'] = $user ? (string) $user->id : ($item->user_id ?? null);
        $data['source_kind'] = 'squad';
        return $this->lifecycle->decorateList($data);
    }

    private function formatSquad(object $item): array
    {
        $data = collect((array) $item)->except(['deleted_at', 'password', 'password_plain_encrypted'])->all();
        $data['password'] = '';
        $data['password_is_decryptable'] = false;
        $data['assignment_name'] = $this->resolveAssignmentName($item->assignment ?? null);
        $user = $this->findUserForSquad($item);
        $data['has_squad'] = true;
        $data['has_user'] = $user !== null;
        $data['user_id'] = $user ? (string) $user->id : ($item->user_id ?? null);
        $data['source_kind'] = 'squad';
        $data['user'] = $user ? $this->serializeUserModel($user) : null;
        $data['user_candidate'] = ! $user ? $this->findUserCandidate($item) : null;
        $data['pivot'] = [
            'key' => 'nisj',
            'nisj' => $this->squadWiring->normalizeNisj($item->nisj ?? null),
            'is_wired' => $user !== null,
        ];
        return $this->lifecycle->decorateDetail($data);
    }

    private function buildNonSquadUserQuery()
    {
        $query = User::query()
            // Non-Squad hanya berisi role eksplisit Stakeholder/Observer atau user tanpa NISJ.
            // User operasional dengan NISJ dimaterialisasi ke HR_squads oleh reconciliation.
            ->where(function ($eligible) {
                $eligible->whereHas('accessAssignment.role', function ($role) {
                    $role->whereIn('code', ['STAKEHOLDER', 'OBSERVER']);
                })->orWhere(function ($withoutNisj) {
                    $withoutNisj->where(function ($userNisj) {
                        $userNisj->whereNull('users.nisj')
                            ->orWhereRaw("TRIM(COALESCE(users.nisj, '')) = ''");
                    })->whereDoesntHave('employee', function ($employee) {
                        $employee->whereNotNull('nisj')
                            ->whereRaw("TRIM(COALESCE(nisj, '')) <> ''");
                    });
                });
            })
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from(self::TABLE.' as pivot_squads')
                    ->whereNull('pivot_squads.deleted_at')
                    ->where(function ($match) {
                        $match->whereColumn('pivot_squads.user_id', 'users.id')
                            ->orWhere(function ($byNisj) {
                                $byNisj->whereNotNull('users.nisj')
                                    ->whereRaw("TRIM(COALESCE(users.nisj, '')) <> ''")
                                    ->whereRaw('LOWER(TRIM(pivot_squads.nisj)) = LOWER(TRIM(users.nisj))');
                            });
                    });
            });

        if (Schema::hasColumn('users', 'hr_retired_at')) {
            $query->whereNull('users.hr_retired_at');
        }

        return $query;
    }

    private function nonSquadIndex(Request $request, int|string $perPage)
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable(self::TABLE)) {
            return ApiResponse::ok([
                'items' => [],
                'pagination' => ['current_page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
            ], 'OK');
        }

        $query = $this->buildNonSquadUserQuery()
            ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('nisj', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('accessAssignment.role', fn ($role) => $role->where('code', 'like', "%{$search}%"));
            });
        }

        $outlet = trim((string) $request->query('outlet', ''));
        if ($outlet !== '') {
            $query->where(function ($inner) use ($outlet) {
                $inner->where('outlet_id', $outlet)
                    ->orWhereHas('employee.assignment.outlet', function ($outletQuery) use ($outlet) {
                        $outletQuery->where('id', $outlet)
                            ->orWhere('name', $outlet)
                            ->orWhere('code', $outlet)
                            ->orWhere('hr_outlet_id', $outlet);
                    });
            });
        }

        $direction = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'full_name' => 'name',
            'nisj' => 'nisj',
            'email' => 'email',
            'status' => 'is_active',
        ];
        $query->orderBy($sortColumns[$request->query('sort_by', 'full_name')] ?? 'name', $direction)
            ->orderBy('id');

        if ($perPage === 'all') {
            $rows = $query->get();
            return ApiResponse::ok([
                'items' => $rows->map(fn (User $user) => $this->formatNonSquadUser($user))->values(),
                'counts' => $this->statusCounts(),
                'pagination' => ['current_page' => 1, 'per_page' => 'all', 'total' => $rows->count(), 'last_page' => 1],
            ], 'OK');
        }

        $paginator = $query->paginate($perPage);
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (User $user) => $this->formatNonSquadUser($user))->values(),
            'counts' => $this->statusCounts(),
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

    private function serializeUser($userId, mixed $nisj = null): ?array
    {
        $user = $this->squadWiring->findUserByNisj($nisj);
        if (! $user && $userId) {
            $user = User::query()
                ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
                ->find($userId);
        }

        return $user ? $this->serializeUserModel($user) : null;
    }

    private function findUserCandidate(object $squad): ?array
    {
        $candidate = $this->squadWiring->findUserByNisj($squad->nisj ?? null);
        if (! $candidate) {
            return null;
        }

        $linkedElsewhere = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('id', '<>', $squad->id)
            ->where(function ($query) use ($candidate) {
                $query->where('user_id', (string) $candidate->id)
                    ->orWhereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($this->squadWiring->normalizeNisj($candidate->nisj))]);
            })
            ->exists();

        if ($linkedElsewhere) {
            return null;
        }

        return [
            'id' => (string) $candidate->id,
            'name' => $candidate->name,
            'nisj' => $candidate->nisj,
            'username' => $candidate->username,
            'email' => $candidate->email,
        ];
    }

    private function findUserForSquad(object $squad): ?User
    {
        $user = $this->squadWiring->findUserByNisj($squad->nisj ?? null);
        if (! $user && ! empty($squad->user_id)) {
            $user = User::query()
                ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
                ->find($squad->user_id);
        }
        return $user;
    }

    private function serializeUserModel(User $user): array
    {
        $user->loadMissing(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level']);
        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $user->outlet;

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'full_name' => $employee?->full_name ?: $user->name,
            'nickname' => $employee?->nickname,
            'nisj' => $user->nisj ?: $employee?->nisj,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'employee' => $employee ? [
                'id' => (string) $employee->id,
                'employee_no' => $employee->hr_employee_id,
                'employment_status' => $employee->employment_status,
            ] : null,
            'assignment' => $assignment ? [
                'id' => (string) $assignment->id,
                'role_title' => $assignment->role_title,
                'start_date' => optional($assignment->start_date)->toDateString(),
                'end_date' => optional($assignment->end_date)->toDateString(),
                'status' => $assignment->status,
            ] : null,
            'outlet' => $outlet ? [
                'id' => (string) $outlet->id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'type' => $outlet->type,
            ] : null,
            'access_role' => $user->accessAssignment?->role?->code,
            'access_role_name' => $user->accessAssignment?->role?->name,
            'access_level' => $user->accessAssignment?->level?->code,
            'access_level_name' => $user->accessAssignment?->level?->name,
        ];
    }

    private function formatNonSquadUser(User $user): array
    {
        $user->loadMissing(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level']);
        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $user->outlet;
        $columns = Schema::getColumnListing(self::TABLE);
        $data = array_fill_keys($columns, null);

        $data = array_merge($data, [
            'id' => 'user:'.(string) $user->id,
            'user_id' => (string) $user->id,
            'full_name' => $employee?->full_name ?: $user->name,
            'nickname' => $employee?->nickname,
            'nisj' => $user->nisj ?: $employee?->nisj,
            'email' => $user->email,
            'status' => (bool) $user->is_active ? 'active' : 'inactive',
            'assignment' => $outlet?->id ? (string) $outlet->id : null,
            'assignment_name' => $outlet?->name,
            'assignment_type' => $outlet?->type,
            'position_name' => $assignment?->role_title,
            'role_name' => $user->accessAssignment?->role?->code,
            'access_role' => $user->accessAssignment?->role?->code,
            'access_level' => $user->accessAssignment?->level?->code,
            'contract_start_date' => optional($assignment?->start_date)->toDateString(),
            'contract_end_date' => optional($assignment?->end_date)->toDateString(),
            'username' => $user->username,
            'updated_at' => $user->updated_at,
            'password' => '',
            'password_is_decryptable' => false,
            'has_squad' => false,
            'has_user' => true,
            'source_kind' => 'user_without_squad',
            'user' => $this->serializeUserModel($user),
            'user_candidate' => null,
            'pivot' => [
                'key' => 'nisj',
                'nisj' => $this->squadWiring->normalizeNisj($user->nisj ?: $employee?->nisj),
                'is_wired' => false,
            ],
        ]);

        unset($data['deleted_at'], $data['password_plain_encrypted']);
        return $data;
    }

    private function validateProvisionUserInput(Request $request, bool $prefixed = true)
    {
        $prefix = $prefixed ? 'user_' : '';
        $password = $prefix.'password';
        $rules = [
            $prefix.'name' => ['nullable', 'string', 'max:255'],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
            $prefix.'username' => ['nullable', 'string', 'max:100'],
            $prefix.'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            $prefix.'access_role_id' => ['nullable', 'string', Rule::exists('access_roles', 'id')],
            $prefix.'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            $password => ['nullable', 'string', 'min:8'],
            $password.'_confirmation' => ['nullable', 'same:'.$password],
        ];

        return Validator::make($request->all(), $rules);
    }

    private function provisionUserForSquad(User $actor, object $squad, array $input = []): array
    {
        $nisj = $this->squadWiring->normalizeNisj($squad->nisj ?? null);
        if ($nisj === '') {
            throw new InvalidArgumentException('NISJ wajib diisi untuk generate Data User.');
        }

        $existing = $this->squadWiring->findUserByNisj($nisj);
        if ($existing) {
            $this->squadWiring->wireExistingUserToSquad($existing, $squad->id);
            return [
                'status' => 'existing_user_linked',
                'user_created' => false,
                'user_linked' => true,
                'user_id' => (string) $existing->id,
                'user_unchanged' => true,
                'default_password_used' => false,
            ];
        }

        $pick = function (string $key, mixed $fallback = null) use ($input) {
            $prefixed = 'user_'.$key;
            return array_key_exists($prefixed, $input) ? $input[$prefixed] : ($input[$key] ?? $fallback);
        };

        $requestedPassword = trim((string) $pick('password', ''));
        $password = $requestedPassword !== '' ? $requestedPassword : 'password123';
        $outletId = trim((string) $pick('outlet_id', '')) ?: $this->resolveAssignmentOutletId($squad->assignment ?? null);
        $roleId = $this->resolveProvisionAccessRoleId($pick('access_role_id'), $squad);
        $levelId = $this->resolveProvisionAccessLevelId($pick('access_level_id'), $squad, $outletId);

        $created = $this->userManagement->createUser($actor, [
            'name' => trim((string) $pick('name', $squad->full_name ?? $nisj)) ?: $nisj,
            'email' => $this->availableUserEmail($pick('email', $squad->email ?? null), $nisj),
            'username' => $this->availableUsername($pick('username', $nisj), $nisj),
            'nisj' => $nisj,
            'assignment_role_title' => $squad->position_name ?? $squad->role_name ?? null,
            'outlet_id' => $outletId,
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'password' => $password,
            'password_confirmation' => $password,
            'is_active' => strtolower((string) ($squad->status ?? 'active')) !== 'inactive',
        ]);

        $user = $created['user'];
        $this->squadWiring->wireExistingUserToSquad($user, $squad->id);

        return [
            'status' => 'user_created',
            'user_created' => true,
            'user_linked' => true,
            'user_id' => (string) $user->id,
            'user_unchanged' => false,
            'default_password_used' => $requestedPassword === '',
        ];
    }

    private function resolveProvisionAccessRoleId(mixed $requestedId, object $squad): string
    {
        $requestedId = trim((string) $requestedId);
        if ($requestedId !== '' && DB::table('access_roles')->where('id', $requestedId)->exists()) {
            return $requestedId;
        }

        $code = strtoupper(trim((string) ($squad->access_role ?? $squad->role_name ?? 'SQUAD_DEFAULT')));
        if ($code === '' || $code === 'SQUAD') {
            $code = 'SQUAD_DEFAULT';
        }

        $role = DB::table('access_roles')->where('code', $code)->first()
            ?: DB::table('access_roles')->where('code', 'SQUAD_DEFAULT')->first()
            ?: DB::table('access_roles')->where('code', 'CASHIER')->first()
            ?: DB::table('access_roles')->orderBy('name')->first();

        if (! $role) {
            throw new InvalidArgumentException('Master Access Role belum tersedia.');
        }
        return (string) $role->id;
    }

    private function resolveProvisionAccessLevelId(mixed $requestedId, object $squad, ?string $outletId): ?string
    {
        $requestedId = trim((string) $requestedId);
        if ($requestedId !== '' && DB::table('access_levels')->where('id', $requestedId)->exists()) {
            return $requestedId;
        }

        $code = strtoupper(trim((string) ($squad->access_level ?? '')));
        $level = $code !== '' ? DB::table('access_levels')->where('code', $code)->first() : null;
        $level = $level
            ?: ($outletId ? DB::table('access_levels')->where('code', 'OUTLET')->first() : null)
            ?: DB::table('access_levels')->where('code', 'DEFAULT')->first()
            ?: DB::table('access_levels')->orderBy('name')->first();

        return $level ? (string) $level->id : null;
    }

    private function availableUsername(mixed $requested, string $nisj): string
    {
        $base = trim((string) $requested) ?: $nisj;
        $candidate = $base;
        $suffix = 2;
        while (User::query()->whereRaw('LOWER(TRIM(`username`)) = ?', [mb_strtolower($candidate)])->exists()) {
            $candidate = mb_substr($base, 0, 90).'-'.$suffix++;
        }
        return $candidate;
    }

    private function availableUserEmail(mixed $requested, string $nisj): string
    {
        $requested = strtolower(trim((string) $requested));
        if ($requested !== '' && filter_var($requested, FILTER_VALIDATE_EMAIL) && ! User::query()->whereRaw('LOWER(TRIM(`email`)) = ?', [$requested])->exists()) {
            return $requested;
        }

        $local = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower($nisj)) ?: 'squad';
        $candidate = $local.'@hr-squad.local';
        $suffix = 2;
        while (User::query()->whereRaw('LOWER(TRIM(`email`)) = ?', [$candidate])->exists()) {
            $candidate = $local.'-'.$suffix++.'@hr-squad.local';
        }
        return $candidate;
    }


    /**
     * Khusus import Data Squad:
     * - pertahankan email Excel bila belum dipakai identitas lain;
     * - bila sudah taken di HR_squads atau users, ubah otomatis ke
     *   <username>@gmail.com;
     * - bila fallback juga taken, tambahkan suffix -2, -3, dst.
     *
     * Existing User dengan NISJ yang sama tidak dianggap konflik karena
     * import memang tidak mengubah Data User existing.
     */
    private function resolveImportEmailAvailability(mixed $requested, string $nisj, ?int $ignoreSquadId = null): array
    {
        $original = strtolower(trim((string) $requested));
        if ($original === '' || ! filter_var($original, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $original !== '' ? $original : null,
                'original' => $original !== '' ? $original : null,
                'changed' => false,
            ];
        }

        $matchingUser = $this->squadWiring->findUserByNisj($nisj);
        $ignoreUserId = $matchingUser?->id ? (string) $matchingUser->id : null;

        if (! $this->importEmailTaken($original, $ignoreSquadId, $ignoreUserId)) {
            return [
                'email' => $original,
                'original' => $original,
                'changed' => false,
            ];
        }

        $username = trim((string) ($matchingUser?->username ?? ''));
        if ($username === '') {
            $username = $this->availableUsername($nisj, $nisj);
        }

        $local = strtolower($username);
        $local = preg_replace('/[^a-z0-9._-]+/i', '-', $local) ?: '';
        $local = trim($local, '.-_');
        if ($local === '') {
            $local = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower($nisj)) ?: 'squad';
            $local = trim($local, '.-_') ?: 'squad';
        }

        $candidate = $local.'@gmail.com';
        $suffix = 2;
        while ($this->importEmailTaken($candidate, $ignoreSquadId, $ignoreUserId)) {
            $candidate = $local.'-'.$suffix++.'@gmail.com';
        }

        return [
            'email' => $candidate,
            'original' => $original,
            'changed' => true,
        ];
    }

    private function importEmailTaken(string $email, ?int $ignoreSquadId = null, ?string $ignoreUserId = null): bool
    {
        $normalized = strtolower(trim($email));

        $squadQuery = DB::table(self::TABLE)
            ->whereRaw('LOWER(TRIM(`email`)) = ?', [$normalized]);
        if ($ignoreSquadId) {
            $squadQuery->where('id', '<>', $ignoreSquadId);
        }
        if ($squadQuery->exists()) {
            return true;
        }

        $userQuery = User::query()
            ->whereRaw('LOWER(TRIM(`email`)) = ?', [$normalized]);
        if ($ignoreUserId !== null && $ignoreUserId !== '') {
            $userQuery->where('id', '<>', $ignoreUserId);
        }

        return $userQuery->exists();
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
            '10012500001', 'CONTOH SQUAD', 'CONTOH', '3573xxxxxxxxxxxx', 'Alamat lengkap',
            'MALANG', '1998-01-31', 'Laki-Laki', 'Islam', 'SMA', 'Belum Menikah', '0',
            '62812xxxx', 'contoh@email.com', 'active', 'OFFICIAL', 'BCA', '1234567890',
            '', '', '', '0', 'SPT', now()->toDateString(), '', 'Outlet A', '', '', 'BARISTA',
            'TIER 1', '5000000', '200000', '104', '25000', '0', '0', '0', '0', '0', 'SQUAD', '3',
        ];
    }

    private function readImportRows($file): array
    {
        return $this->readXlsxRows($file->getRealPath());
    }

    private function readXlsxRows(string $path): array
    {
        // Use the shared OpenXML reader instead of parsing worksheet XML via
        // SimpleXML directly. Excel generators are free to emit the SpreadsheetML
        // namespace using a prefix (for example <x:worksheet>/<x:sheetData>),
        // while the previous implementation only recognized unprefixed nodes.
        // SimpleXlsxService reads elements by local name, supports prefixed and
        // default namespaces, shared strings, inline strings, and the ZIP fallback.
        return $this->xlsx->read($path);
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

    private function resolveImportHeaderMap(array $rawHeader): array
    {
        $aliasLookup = [];
        foreach ($this->importHeaderAliases() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasLookup[mb_strtolower($this->normalizeHeader((string) $alias))] = $canonical;
            }
        }

        $map = [];
        $seenHeaders = [];
        $duplicates = [];
        $unsupported = [];

        foreach (array_values($rawHeader) as $index => $headerValue) {
            $header = $this->normalizeHeader((string) $headerValue);
            $normalized = mb_strtolower($header);
            if ($normalized === '') {
                continue;
            }

            $canonical = $aliasLookup[$normalized] ?? null;
            if (! $canonical) {
                $unsupported[] = $header;
                continue;
            }

            if (isset($map[$canonical])) {
                $duplicates[$canonical] ??= [
                    'canonical' => $canonical,
                    'headers' => [$seenHeaders[$canonical]],
                ];
                $duplicates[$canonical]['headers'][] = $header;
                continue;
            }

            $map[$canonical] = $index;
            $seenHeaders[$canonical] = $header;
        }

        $required = ['nisj', 'full_name'];
        $missing = array_values(array_filter($required, fn ($column) => ! array_key_exists($column, $map)));

        return [
            'map' => $map,
            'missing' => $missing,
            'duplicates' => array_values($duplicates),
            'unsupported' => array_values(array_unique($unsupported)),
        ];
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
        // Format canonical Iterasi 03. Export dapat langsung diedit lalu di-import kembali.
        return [
            'nisj', 'full_name', 'nickname', 'nik', 'address', 'birth_place', 'birth_date',
            'gender', 'religion', 'education', 'marital_status', 'children_count', 'whatsapp', 'email',
            'status', 'employee_type', 'bank_name', 'bank_account', 'bpjs_number', 'bpjstk_number',
            'faskes', 'ppi_status', 'contract_type', 'contract_start_date', 'contract_end_date',
            'assignment', 'chamber_name', 'division_name', 'position_name', 'salary_tier_name',
            'basic_salary', 'daily_salary', 'minute_deduction', 'hourly_overtime', 'bonus',
            'family_allowance', 'position_allowance', 'cashbon', 'other', 'role_name', 'leave_quota',
        ];
    }

    private function importHeaderAliases(): array
    {
        $aliases = [];
        foreach ($this->headers() as $header) {
            $aliases[$header] = [$header];
        }

        return array_merge($aliases, [
            'children_count' => ['children_count', 'anak'],
            'whatsapp' => ['whatsapp', 'phone'],
            'status' => ['status', 'employment_status'],
            'employee_type' => ['employee_type', 'employment_type'],
            'salary_tier_name' => ['salary_tier_name', 'tier'],
            'contract_start_date' => ['contract_start_date', 'join_date'],
            'contract_end_date' => ['contract_end_date', 'resign_date'],
            'assignment' => ['assignment', 'assignment_id'],
            'minute_deduction' => ['minute_deduction', 'basic_cut'],
            'hourly_overtime' => ['hourly_overtime', 'basic_ovt'],
            'bonus' => ['bonus', 'bonus1'],
            'family_allowance' => ['family_allowance', 'bonus2'],
            'position_allowance' => ['position_allowance', 'bonus3'],
            'role_name' => ['role_name', 'role'],
        ]);
    }

    private function rowForSpreadsheet(object $row): array
    {
        return [
            $row->nisj ?? '',
            $row->full_name ?? '',
            $row->nickname ?? '',
            $row->nik ?? '',
            $row->address ?? '',
            $row->birth_place ?? '',
            $row->birth_date ?? '',
            $row->gender ?? '',
            $row->religion ?? '',
            $row->education ?? '',
            $row->marital_status ?? '',
            $row->children_count ?? 0,
            $row->whatsapp ?? '',
            $row->email ?? '',
            strtolower((string) ($row->status ?? 'active')) === 'inactive' ? 'inactive' : 'active',
            $row->employee_type ?? '',
            $row->bank_name ?? '',
            $row->bank_account ?? '',
            $row->bpjs_number ?? '',
            $row->bpjstk_number ?? '',
            $row->faskes ?? '',
            ! empty($row->ppi_status) ? '1' : '0',
            $row->contract_type ?? '',
            $row->contract_start_date ?? '',
            $row->contract_end_date ?? '',
            $row->assignment ?? '',
            $row->chamber_name ?? '',
            $row->division_name ?? '',
            $row->position_name ?? '',
            $row->salary_tier_name ?? '',
            $row->basic_salary ?? 0,
            $row->daily_salary ?? 0,
            $row->minute_deduction ?? 0,
            $row->hourly_overtime ?? 0,
            $row->bonus ?? 0,
            $row->family_allowance ?? 0,
            $row->position_allowance ?? 0,
            $row->cashbon ?? 0,
            $row->other ?? 0,
            $row->role_name ?? 'SQUAD',
            $row->leave_quota ?? 3,
        ];
    }

    private function mapImportData(array $data, bool $existing = false): array
    {
        $mapped = [];
        $has = fn (string $key): bool => array_key_exists($key, $data);
        $text = fn ($value) => $this->normalizeHrLegacyNullableValue($value);
        $number = fn ($value) => $this->normalizeImportNumber($value);
        $integer = fn ($value) => $this->normalizeImportInteger($value);
        $put = function (string $key, mixed $value) use (&$mapped): void {
            $mapped[$key] = $value;
        };

        if ($has('nisj')) $put('nisj', $this->normalizeImportIdentity($data['nisj']));
        if ($has('full_name')) $put('full_name', $text($data['full_name']));
        if ($has('nickname')) $put('nickname', $text($data['nickname']));
        if ($has('nik')) $put('nik', $text($data['nik']));
        if ($has('address')) $put('address', $text($data['address']));
        if ($has('birth_place')) $put('birth_place', $text($data['birth_place']));
        if ($has('birth_date')) $put('birth_date', $this->normalizeImportDate($data['birth_date']));
        if ($has('gender')) $put('gender', $this->normalizeHrLegacyGender($data['gender']));
        if ($has('religion')) $put('religion', $text($data['religion']));
        if ($has('education')) $put('education', $text($data['education']));
        if ($has('marital_status')) $put('marital_status', $text($data['marital_status']));
        if ($has('children_count')) $put('children_count', $integer($data['children_count']));
        if ($has('whatsapp')) $put('whatsapp', $text($data['whatsapp']));
        if ($has('email')) {
            $email = strtolower((string) $text($data['email']));
            $put('email', $email !== '' ? $email : null);
        }
        if ($has('status')) $put('status', $this->normalizeHrLegacyStatus($data['status']));
        if ($has('employee_type')) $put('employee_type', $this->normalizeHrLegacyEmployeeType($data['employee_type']));
        if ($has('bank_name')) $put('bank_name', $text($data['bank_name']));
        if ($has('bank_account')) $put('bank_account', $text($data['bank_account']));
        if ($has('bpjs_number')) $put('bpjs_number', $text($data['bpjs_number']));
        if ($has('bpjstk_number')) $put('bpjstk_number', $text($data['bpjstk_number']));
        if ($has('faskes')) $put('faskes', $text($data['faskes']));
        if ($has('ppi_status')) {
            $raw = strtolower(trim((string) $data['ppi_status']));
            $put('ppi_status', in_array($raw, ['1', 'true', 'ya', 'yes', 'y', 'aktif'], true));
        }
        if ($has('contract_type')) $put('contract_type', $this->normalizeContractType($data['contract_type']));
        if ($has('contract_start_date')) $put('contract_start_date', $this->normalizeImportDate($data['contract_start_date']));
        if ($has('contract_end_date')) {
            $endDate = $this->normalizeImportDate($data['contract_end_date']);
            $put('contract_end_date', $endDate === '1970-01-01' ? null : $endDate);
        }
        if ($has('assignment')) $put('assignment', $text($data['assignment']));
        if ($has('chamber_name')) $put('chamber_name', $text($data['chamber_name']));
        if ($has('division_name')) $put('division_name', $text($data['division_name']));
        if ($has('position_name')) $put('position_name', $text($data['position_name']));
        if ($has('salary_tier_name')) $put('salary_tier_name', $text($data['salary_tier_name']));
        foreach (['basic_salary', 'daily_salary', 'minute_deduction', 'hourly_overtime', 'bonus', 'family_allowance', 'position_allowance', 'cashbon', 'other'] as $moneyField) {
            if ($has($moneyField)) $put($moneyField, $number($data[$moneyField]));
        }
        if ($has('role_name')) $put('role_name', $this->normalizeUserRole($data['role_name']));
        if ($has('leave_quota')) $put('leave_quota', $integer($data['leave_quota']));

        if (! $existing) {
            $defaults = [
                'full_name' => null,
                'nickname' => null,
                'nik' => null,
                'address' => null,
                'birth_place' => null,
                'birth_date' => null,
                'gender' => null,
                'religion' => null,
                'education' => null,
                'marital_status' => null,
                'children_count' => 0,
                'whatsapp' => null,
                'email' => null,
                'status' => 'active',
                'nisj' => '',
                'employee_type' => null,
                'bank_name' => null,
                'bank_account' => null,
                'bpjs_number' => null,
                'bpjstk_number' => null,
                'faskes' => null,
                'ppi_status' => false,
                'contract_type' => 'SPT',
                'contract_start_date' => null,
                'contract_end_date' => null,
                'assignment' => null,
                'chamber_name' => null,
                'division_name' => null,
                'position_name' => null,
                'salary_tier_name' => null,
                'basic_salary' => 0,
                'daily_salary' => 0,
                'minute_deduction' => 0,
                'hourly_overtime' => 0,
                'bonus' => 0,
                'family_allowance' => 0,
                'position_allowance' => 0,
                'cashbon' => 0,
                'other' => 0,
                'role_name' => 'SQUAD',
                'leave_quota' => 3,
            ];
            $mapped = array_merge($defaults, $mapped);
        }

        return $mapped;
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

    private function duplicateImportNisjLineMap(array $rows, mixed $nisjIndex): array
    {
        if (! is_int($nisjIndex) && ! ctype_digit((string) $nisjIndex)) {
            return [];
        }

        $nisjIndex = (int) $nisjIndex;
        $firstLineByNisj = [];
        $duplicateLineMap = [];

        foreach (array_values($rows) as $index => $row) {
            $line = $index + 2;
            if (count(array_filter((array) $row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $nisj = $this->normalizeImportIdentity($row[$nisjIndex] ?? '');
            $key = mb_strtolower($nisj);
            if ($key === '') {
                continue;
            }

            if (isset($firstLineByNisj[$key])) {
                $duplicateLineMap[$line] = $firstLineByNisj[$key];
                continue;
            }

            $firstLineByNisj[$key] = $line;
        }

        return $duplicateLineMap;
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
        $nisj = $this->normalizeImportIdentity($mapped['nisj'] ?? '');
        if ($nisj === '') {
            return null;
        }

        return DB::table(self::TABLE)
            ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($nisj)])
            ->first();
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

    private function detectImportChanges(object $existing, array $payload): array
    {
        $changes = [];
        foreach ($payload as $field => $newValue) {
            $oldValue = $existing->{$field} ?? null;
            if ($this->normalizeComparableImportValue($field, $oldValue) === $this->normalizeComparableImportValue($field, $newValue)) {
                continue;
            }
            $changes[$field] = [
                'from' => $oldValue,
                'to' => $newValue,
            ];
        }
        return $changes;
    }

    private function normalizeComparableImportValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($field === 'ppi_status') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (in_array($field, [
            'children_count', 'leave_quota', 'basic_salary', 'daily_salary', 'minute_deduction',
            'hourly_overtime', 'bonus', 'family_allowance', 'position_allowance', 'cashbon', 'other',
        ], true)) {
            return number_format((float) $value, 4, '.', '');
        }

        if (in_array($field, ['birth_date', 'contract_start_date', 'contract_end_date'], true)) {
            return substr((string) $value, 0, 10);
        }

        return trim((string) $value);
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
        $labels = array_combine($this->headers(), $this->headers()) ?: [];
        return array_merge($labels, [
            'salary_tier_id' => 'salary_tier_name',
            'photo' => 'photo',
            'username' => 'Data User / username',
            'password' => 'Data User / password',
            'access_role' => 'Data User / access_role',
            'access_level' => 'Data User / access_level',
        ]);
    }

    private function describeImportException(\Throwable $exception): array
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);

        $rules = [
            ['needle' => 'hr_squads_nisj_unique', 'column' => 'nisj', 'message' => 'NISJ sudah dipakai Data Squad lain. Pastikan satu NISJ hanya muncul satu kali.'],
            ['needle' => 'hr_squads_email_unique', 'column' => 'email', 'message' => 'Email sudah dipakai Data Squad lain. Kosongkan atau gunakan email berbeda.'],
            ['needle' => 'hr_squads_username_unique', 'column' => 'username', 'message' => 'Username legacy sudah dipakai Data Squad lain.'],
            ['needle' => 'hr_squads_user_id_unique', 'column' => 'user_id', 'message' => 'Data User sudah terhubung ke Data Squad lain. Periksa pivot NISJ.'],
            ['needle' => 'foreign key constraint', 'column' => 'reference', 'message' => 'Referensi master tidak valid atau sudah dihapus. Periksa outlet/tier/relasi terkait.'],
            ['needle' => 'data too long', 'column' => 'value', 'message' => 'Nilai melebihi panjang maksimum kolom database.'],
            ['needle' => 'incorrect date', 'column' => 'date', 'message' => 'Format tanggal tidak valid. Gunakan YYYY-MM-DD, contoh 2026-07-26.'],
            ['needle' => 'incorrect decimal', 'column' => 'salary', 'message' => 'Nilai angka/gaji tidak valid. Gunakan angka tanpa simbol mata uang.'],
        ];

        foreach ($rules as $rule) {
            if (str_contains($lower, $rule['needle'])) {
                return [
                    'column' => $rule['column'],
                    'message' => $rule['message'],
                    'technical_message' => $message,
                ];
            }
        }

        return [
            'column' => 'System',
            'message' => $message,
            'technical_message' => $message,
        ];
    }

    private function formatImportExceptionRowError(int $line, array $rawData, array $mapped, \Throwable $exception): array
    {
        $description = $this->describeImportException($exception);
        return [
            'line' => $line,
            'row_number' => $line,
            'name' => $mapped['full_name'] ?? ($rawData['full_name'] ?? ''),
            'nisj' => $mapped['nisj'] ?? ($rawData['nisj'] ?? ''),
            'details' => [[
                'column' => $description['column'],
                'field' => 'exception',
                'value' => $rawData[$description['column']] ?? '',
                'message' => $description['message'],
            ], [
                'column' => 'Baris XLSX',
                'field' => 'row',
                'value' => collect($rawData)->map(fn ($value, $key) => $key . '=' . (is_scalar($value) ? (string) $value : ''))->implode('; '),
                'message' => 'Baris ini gagal diproses, tetapi import dilanjutkan ke baris berikutnya.',
            ], [
                'column' => 'Technical',
                'field' => 'technical_message',
                'value' => '',
                'message' => $description['technical_message'],
            ]],
            'error_text' => $description['column'].': '.$description['message'],
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
