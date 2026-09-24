<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrContractStageLifecycleService
{
    public const STAGES = ['SPT', 'PKWT1', 'PKWT2', 'PKWT3', 'PKWT4', 'PKWT5', 'PKWTT'];

    private const NEXT_STAGE = [
        'SPT' => 'PKWT1',
        'PKWT1' => 'PKWT2',
        'PKWT2' => 'PKWT3',
        'PKWT3' => 'PKWT4',
        'PKWT4' => 'PKWT5',
        'PKWT5' => 'PKWTT',
        'PKWTT' => null,
    ];

    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function references(Request $request): array
    {
        return [
            'outlets' => $this->scope->options($request),
            'stages' => collect(self::STAGES)->map(fn (string $stage) => [
                'value' => $stage,
                'label' => $stage,
                'next' => self::NEXT_STAGE[$stage],
            ])->values()->all(),
            'groups' => [
                ['value' => 'SQUAD', 'label' => 'Squad / Outlet'],
                ['value' => 'MANAGEMENT', 'label' => 'Management / Warehouse'],
                ['value' => 'FINANCE', 'label' => 'Finance'],
            ],
            'duration_rules' => $this->durationRules(),
            'today' => now('Asia/Jakarta')->toDateString(),
            'normalization_note' => 'Plain PKWT legacy tidak ditebak urutannya. Record tersebut harus ditetapkan stage secara manual agar history tidak termutasi diam-diam.',
        ];
    }

    public function index(Request $request, array $filters): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return $this->emptyPage();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        if (! in_array($perPage, [10, 25, 50, 100, 200], true)) $perPage = 25;

        $query = $this->baseQuery($allowed);
        $this->applyFilters($query, $filters, $allowed);
        $query->orderByRaw("CASE WHEN c.lifecycle_review_status = 'needs_review' THEN 0 ELSE 1 END")
            ->orderByRaw('c.next_contract_due_at IS NULL ASC')
            ->orderBy('c.next_contract_due_at')
            ->orderBy('s.full_name');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $items = collect($paginator->items())->map(fn (object $row) => $this->rowPayload($row))->values()->all();

        $countsQuery = $this->baseQuery($allowed);
        $this->applyFilters($countsQuery, array_diff_key($filters, ['stage' => true, 'review_status' => true]), $allowed);
        $counts = $countsQuery->select([])->selectRaw(
            "COUNT(*) total, SUM(CASE WHEN c.lifecycle_review_status='needs_review' THEN 1 ELSE 0 END) needs_review, SUM(CASE WHEN c.lifecycle_stage='PKWTT' THEN 1 ELSE 0 END) pkwtt, SUM(CASE WHEN c.next_contract_due_at IS NOT NULL AND c.next_contract_due_at < ? THEN 1 ELSE 0 END) overdue, SUM(CASE WHEN c.next_contract_due_at BETWEEN ? AND ? THEN 1 ELSE 0 END) due_30",
            [now('Asia/Jakarta')->toDateString(), now('Asia/Jakarta')->toDateString(), now('Asia/Jakarta')->addDays(30)->toDateString()]
        )->first();

        return [
            'items' => $items,
            'summary' => [
                'total' => (int) ($counts->total ?? 0),
                'needs_review' => (int) ($counts->needs_review ?? 0),
                'pkwtt' => (int) ($counts->pkwtt ?? 0),
                'overdue' => (int) ($counts->overdue ?? 0),
                'due_30' => (int) ($counts->due_30 ?? 0),
            ],
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function resolveStage(Request $request, string $contractId, array $data, ?User $actor): array
    {
        return DB::transaction(function () use ($request, $contractId, $data, $actor) {
            $row = $this->lockedContract($contractId);
            $this->assertScope($request, $row);

            $stage = strtoupper(trim((string) $data['stage']));
            if (! in_array($stage, self::STAGES, true)) {
                throw ValidationException::withMessages(['stage' => ['Stage lifecycle tidak valid.']]);
            }

            $beforeStage = $row->lifecycle_stage ? strtoupper((string) $row->lifecycle_stage) : null;
            $group = $this->resolveGroup($row);
            $nextDue = $stage === 'PKWTT' ? null : $this->nextDue($row->end_date);

            DB::table('HR_contracts')->where('id', $contractId)->update([
                'lifecycle_stage' => $stage,
                'lifecycle_group' => $group,
                'lifecycle_review_status' => 'resolved',
                'lifecycle_review_note' => trim((string) ($data['note'] ?? '')) ?: null,
                'next_contract_due_at' => $nextDue,
                'lifecycle_version' => DB::raw('lifecycle_version + 1'),
                'lifecycle_initialized_at' => $row->lifecycle_initialized_at ?: now(),
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            $fresh = $this->contractById($contractId);
            $this->recordLifecycleEvent($fresh, 'stage_resolved', $beforeStage, $stage, $actor, [
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'historical_dates_preserved' => true,
            ]);
            $this->recordLegacyEvent($fresh, 'contract_lifecycle_resolved', $actor, [
                'before_stage' => $beforeStage,
                'after_stage' => $stage,
                'historical_dates_preserved' => true,
            ]);

            return $this->rowPayload($this->baseQuery([$fresh->outlet_id])->where('c.id', $contractId)->firstOrFail());
        });
    }

    public function transition(Request $request, string $contractId, array $data, ?User $actor): array
    {
        return DB::transaction(function () use ($request, $contractId, $data, $actor) {
            $row = $this->lockedContract($contractId);
            $this->assertScope($request, $row);

            if ((string) $row->lifecycle_review_status !== 'resolved' || ! $row->lifecycle_stage) {
                throw ValidationException::withMessages(['lifecycle' => ['Stage lifecycle belum resolved. Tetapkan stage terlebih dahulu.']]);
            }
            if (strtolower((string) $row->status) === 'terminated') {
                throw ValidationException::withMessages(['lifecycle' => ['Kontrak terminated tidak dapat ditransisikan.']]);
            }

            $current = strtoupper((string) $row->lifecycle_stage);
            $expected = strtoupper(trim((string) $data['expected_stage']));
            if ($expected !== $current) {
                throw ValidationException::withMessages(['expected_stage' => ['Stage telah berubah. Muat ulang data sebelum melakukan transisi.']]);
            }

            $next = self::NEXT_STAGE[$current] ?? null;
            if (! $next) {
                throw ValidationException::withMessages(['lifecycle' => ['PKWTT adalah stage akhir dan tidak memiliki kontrak berikutnya.']]);
            }
            if (! $row->end_date) {
                throw ValidationException::withMessages(['end_date' => ['Tanggal akhir stage aktif belum tersedia. Lengkapi kontrak sebelum transisi.']]);
            }

            $nextStart = Carbon::parse($row->end_date, 'Asia/Jakarta')->addDay()->toDateString();
            $today = now('Asia/Jakarta')->toDateString();
            if ($nextStart > $today) {
                throw ValidationException::withMessages(['lifecycle' => ['Transisi belum dapat dieksekusi sebelum '.$this->dateLabel($nextStart).'. Sistem tetap menampilkan rekomendasi H-30 tanpa mengubah kontrak aktif lebih awal.']]);
            }

            $group = $this->resolveGroup($row);
            $duration = $this->durationFor($next, $group);
            $nextEnd = $duration === null ? null : Carbon::parse($nextStart, 'Asia/Jakarta')->addDays($duration)->toDateString();
            $this->assertNoOverlap($row, $nextStart, $nextEnd);

            $before = $this->snapshot($row);
            $this->recordLifecycleEvent($row, 'stage_transition', $current, $next, $actor, [
                'closed_stage' => $current,
                'closed_stage_start' => $this->date($row->start_date),
                'closed_stage_end' => $this->date($row->end_date),
                'template_duration_days' => $this->durationFor($current, $group),
            ], true);

            $nextDue = $next === 'PKWTT' ? null : Carbon::parse((string) $nextEnd, 'Asia/Jakarta')->addDay()->toDateString();
            DB::table('HR_contracts')->where('id', $contractId)->update([
                'contract_type' => $next,
                'lifecycle_stage' => $next,
                'lifecycle_group' => $group,
                'lifecycle_review_status' => 'resolved',
                'lifecycle_review_note' => null,
                'tmt_date' => $nextStart,
                'start_date' => $nextStart,
                'end_date' => $nextEnd,
                'next_contract_due_at' => $nextDue,
                'lifecycle_version' => DB::raw('lifecycle_version + 1'),
                'status' => 'active',
                'source' => 'lifecycle_i05',
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            $this->syncSquadLegacy($row->squad_id, $next, $nextStart, $nextEnd, $row);
            $fresh = $this->contractById($contractId);
            $after = $this->snapshot($fresh);
            $this->recordLegacyEvent($fresh, 'contract_lifecycle_transition', $actor, [
                'from_stage' => $current,
                'to_stage' => $next,
                'before' => $before,
                'after' => $after,
                'duration_group' => $group,
                'duration_days' => $duration,
            ], $nextStart);

            return $this->rowPayload($this->baseQuery([$fresh->outlet_id])->where('c.id', $contractId)->firstOrFail());
        });
    }

    public function recap(Request $request, array $filters): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return ['summary' => $this->emptyRecapSummary(), 'rows' => [], 'duration_rules' => $this->durationRules()];

        $query = $this->baseQuery($allowed);
        $this->applyFilters($query, $filters, $allowed, recap: true);
        $contracts = $query->orderBy('s.full_name')->orderBy('c.start_date')->get();
        if ($contracts->isEmpty()) return ['summary' => $this->emptyRecapSummary(), 'rows' => [], 'duration_rules' => $this->durationRules()];

        $events = Schema::hasTable('HR_contract_lifecycle_events')
            ? DB::table('HR_contract_lifecycle_events')->whereIn('contract_id', $contracts->pluck('id')->map(fn ($v) => (string) $v)->all())->orderBy('event_at')->get()->groupBy('contract_id')
            : collect();

        $grouped = [];
        foreach ($contracts as $contract) {
            $key = $contract->squad_id ? 'squad:'.$contract->squad_id : 'contract:'.$contract->id;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'identity' => $this->identityFromRow($contract),
                    'stages' => [],
                    'needs_review' => false,
                    'review_notes' => [],
                    'latest_sort' => $this->date($contract->start_date) ?: '0000-00-00',
                ];
            }
            if (($this->date($contract->start_date) ?: '0000-00-00') >= $grouped[$key]['latest_sort']) {
                $grouped[$key]['identity'] = $this->identityFromRow($contract);
                $grouped[$key]['latest_sort'] = $this->date($contract->start_date) ?: '0000-00-00';
            }
            if ((string) $contract->lifecycle_review_status === 'needs_review') {
                $grouped[$key]['needs_review'] = true;
                if ($contract->lifecycle_review_note) $grouped[$key]['review_notes'][] = (string) $contract->lifecycle_review_note;
            }

            foreach (($events[(string) $contract->id] ?? collect()) as $event) {
                if ((string) $event->event_type !== 'stage_transition' || ! $event->from_stage) continue;
                $stage = strtoupper((string) $event->from_stage);
                if (! in_array($stage, self::STAGES, true)) continue;
                $grouped[$key]['stages'][$stage] = $this->stageFromEvent($event);
            }

            $stage = strtoupper(trim((string) ($contract->lifecycle_stage ?? '')));
            if (in_array($stage, self::STAGES, true)) {
                $grouped[$key]['stages'][$stage] = $this->stageFromCurrent($contract);
            }
        }

        $rows = collect($grouped)->map(function (array $entry) {
            $stages = [];
            foreach (self::STAGES as $stage) $stages[$stage] = $entry['stages'][$stage] ?? null;
            return [
                ...$entry['identity'],
                'needs_review' => (bool) $entry['needs_review'],
                'review_notes' => array_values(array_unique($entry['review_notes'])),
                'stages' => $stages,
                'current_stage' => $this->latestStage($stages),
            ];
        })->sortBy(fn (array $row) => strtoupper(($row['outlet_name'] ?? '').'|'.($row['full_name'] ?? '')))->values()->all();

        $summary = [
            'total_people' => count($rows),
            'needs_review' => collect($rows)->where('needs_review', true)->count(),
            'pkwtt' => collect($rows)->where('current_stage', 'PKWTT')->count(),
            'finite_contract' => collect($rows)->filter(fn ($r) => $r['current_stage'] && $r['current_stage'] !== 'PKWTT')->count(),
        ];

        return [
            'summary' => $summary,
            'rows' => $rows,
            'duration_rules' => $this->durationRules(),
            'stage_order' => self::STAGES,
            'template_source' => 'REKAP KONTRAK SPT PKWT 2026.xlsx',
            'note' => 'Preview ERP menampilkan SPT + PKWT1..PKWT5 + PKWTT secara eksplisit. Export XLSX mempertahankan style workbook acuan dan menambahkan blok SPT agar seluruh stage dapat direpresentasikan tanpa kehilangan PKWT5.',
        ];
    }

    public function durationRules(): array
    {
        return [
            ['key' => 'SPT_SQUAD', 'label' => 'DURASI SPT SQUAD 2 BULAN', 'days' => 61],
            ['key' => 'SPT_MANAGEMENT', 'label' => 'DURASI SPT MANAGEMENT 3 BULAN', 'days' => 92],
            ['key' => 'PKWT_FINANCE', 'label' => 'DURASI PKWT FINANCE 6 BULAN', 'days' => 184],
            ['key' => 'PKWT_STANDARD', 'label' => 'DURASI PKWT - 1 TAHUN', 'days' => 365],
            ['key' => 'PKWTT', 'label' => 'PKWTT', 'days' => null],
        ];
    }

    public function contractKind(string $stage, string $group): string
    {
        if ($stage === 'SPT') return $group === 'SQUAD' ? 'SPT SQUAD' : 'SPT MGMT';
        if ($stage === 'PKWTT') return 'PKWTT';
        return $group === 'FINANCE' ? 'PKWT FIN' : 'PKWT';
    }

    public function durationFor(string $stage, string $group): ?int
    {
        if ($stage === 'PKWTT') return null;
        if ($stage === 'SPT') return $group === 'SQUAD' ? 61 : 92;
        return $group === 'FINANCE' ? 184 : 365;
    }

    private function baseQuery(array $allowed)
    {
        return DB::table('HR_contracts as c')
            ->leftJoin('HR_squads as s', 's.id', '=', 'c.squad_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'c.outlet_id')
            ->whereNull('c.deleted_at')
            ->whereIn('c.outlet_id', $allowed)
            ->select([
                'c.*',
                's.full_name', 's.nickname', 's.nisj', 's.salary_tier_name', 's.basic_salary',
                's.division_name as squad_division_name', 's.position_name as squad_position_name',
                'o.name as outlet_name', 'o.code as outlet_code', 'o.type as outlet_type',
            ]);
    }

    private function applyFilters($query, array $filters, array $allowed, bool $recap = false): void
    {
        $outletId = trim((string) ($filters['outlet_id'] ?? ''));
        if ($outletId !== '') {
            if (! in_array($outletId, $allowed, true)) $query->whereRaw('1=0');
            else $query->where('c.outlet_id', $outletId);
        }
        $stage = strtoupper(trim((string) ($filters['stage'] ?? '')));
        if ($stage !== '' && in_array($stage, self::STAGES, true)) $query->where('c.lifecycle_stage', $stage);
        $review = trim((string) ($filters['review_status'] ?? ''));
        if ($review !== '') $query->where('c.lifecycle_review_status', $review);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('s.full_name', 'like', $like)
                    ->orWhere('s.nisj', 'like', $like)
                    ->orWhere('c.contract_no', 'like', $like)
                    ->orWhere('c.position_name', 'like', $like)
                    ->orWhere('o.name', 'like', $like);
            });
        }
        if ($recap) $query->whereNotNull('c.squad_id');
    }

    private function rowPayload(object $row): array
    {
        $stage = strtoupper(trim((string) ($row->lifecycle_stage ?? '')));
        $inferred = $stage ?: $this->inferStage((string) ($row->contract_type ?? ''));
        $group = $row->lifecycle_group ?: $this->resolveGroup($row);
        $next = $stage && isset(self::NEXT_STAGE[$stage]) ? self::NEXT_STAGE[$stage] : null;
        $due = $this->date($row->next_contract_due_at) ?: ($stage && $stage !== 'PKWTT' ? $this->nextDue($row->end_date) : null);
        $today = now('Asia/Jakarta')->startOfDay();
        $daysToDue = $due ? $today->diffInDays(Carbon::parse($due, 'Asia/Jakarta')->startOfDay(), false) : null;
        $dueState = match (true) {
            $stage === 'PKWTT' => 'complete',
            $daysToDue === null => 'missing_date',
            $daysToDue < 0 => 'overdue',
            $daysToDue === 0 => 'due_today',
            $daysToDue <= 30 => 'upcoming',
            default => 'future',
        };
        $reviewStatus = (string) ($row->lifecycle_review_status ?? 'uninitialized');
        $canTransition = $reviewStatus === 'resolved' && $stage !== '' && $stage !== 'PKWTT' && $due && $due <= $today->toDateString() && strtolower((string) $row->status) !== 'terminated';

        return [
            'id' => (string) $row->id,
            'squad_id' => $row->squad_id ? (int) $row->squad_id : null,
            'contract_no' => (string) ($row->contract_no ?? ''),
            'contract_type' => (string) ($row->contract_type ?? ''),
            'status' => (string) ($row->status ?? ''),
            'lifecycle_stage' => $stage ?: null,
            'inferred_stage' => $inferred,
            'lifecycle_group' => $group,
            'lifecycle_review_status' => $reviewStatus,
            'lifecycle_review_note' => $row->lifecycle_review_note,
            'lifecycle_version' => (int) ($row->lifecycle_version ?? 0),
            'full_name' => (string) ($row->full_name ?? '-'),
            'nickname' => (string) ($row->nickname ?? ''),
            'nisj' => (string) ($row->nisj ?? ''),
            'assignment_label' => (string) ($row->assignment_label ?? ''),
            'outlet_id' => (string) ($row->outlet_id ?? ''),
            'outlet_name' => (string) ($row->outlet_name ?? '-'),
            'division_name' => (string) ($row->division_name ?: $row->squad_division_name ?: ''),
            'position_name' => (string) ($row->position_name ?: $row->squad_position_name ?: ''),
            'salary_tier_name' => (string) ($row->salary_tier_name ?? ''),
            'basic_salary' => (float) ($row->basic_salary ?? 0),
            'first_sk_date' => $this->date($row->first_sk_date),
            'start_date' => $this->date($row->start_date),
            'end_date' => $this->date($row->end_date),
            'next_contract_due_at' => $due,
            'next_stage' => $next,
            'next_contract_kind' => $next ? $this->contractKind($next, $group) : null,
            'next_duration_days' => $next ? $this->durationFor($next, $group) : null,
            'days_to_due' => $daysToDue,
            'due_state' => $dueState,
            'can_transition' => $canTransition,
        ];
    }

    private function lockedContract(string $id): object
    {
        $row = DB::table('HR_contracts')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
        if (! $row) throw ValidationException::withMessages(['contract' => ['Kontrak tidak ditemukan.']]);
        return $row;
    }

    private function contractById(string $id): object
    {
        $row = DB::table('HR_contracts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) throw ValidationException::withMessages(['contract' => ['Kontrak tidak ditemukan.']]);
        return $row;
    }

    private function assertScope(Request $request, object $row): void
    {
        if (! $row->outlet_id || ! $this->scope->isOutletAllowed($request, (string) $row->outlet_id)) {
            throw ValidationException::withMessages(['outlet_id' => ['Kontrak berada di luar scope Human Resource user.']]);
        }
    }

    private function resolveGroup(object $row): string
    {
        $text = strtoupper(trim(implode(' ', [
            (string) ($row->division_name ?? ''),
            (string) ($row->position_name ?? ''),
            (string) ($row->squad_division_name ?? ''),
            (string) ($row->squad_position_name ?? ''),
        ])));
        if (str_contains($text, 'FINANCE') || str_contains($text, 'FINANS')) return 'FINANCE';
        $assignment = strtoupper(trim((string) ($row->assignment_label ?? '')));
        if (in_array($assignment, ['MANAGEMENT', 'WAREHOUSE'], true)) return 'MANAGEMENT';
        return 'SQUAD';
    }

    private function inferStage(string $contractType): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', trim($contractType)) ?: '');
        if ($value === '') return null;
        if (str_starts_with($value, 'SPT')) return 'SPT';
        if (in_array($value, ['PKWTT', 'TETAP', 'PERMANENT'], true)) return 'PKWTT';
        foreach ([1, 2, 3, 4, 5] as $number) if ($value === 'PKWT'.$number) return 'PKWT'.$number;
        return null;
    }

    private function nextDue(mixed $endDate): ?string
    {
        if (! $endDate) return null;
        try { return Carbon::parse((string) $endDate, 'Asia/Jakarta')->addDay()->toDateString(); }
        catch (\Throwable) { return null; }
    }

    private function assertNoOverlap(object $current, string $start, ?string $end): void
    {
        if (! $current->squad_id) return;
        $query = DB::table('HR_contracts')
            ->where('squad_id', $current->squad_id)
            ->where('id', '<>', $current->id)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status,'active')) <> 'terminated'")
            ->where(function ($q) use ($end) {
                if ($end) $q->whereNull('start_date')->orWhere('start_date', '<=', $end);
            })
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $start);
            });
        if ($query->exists()) {
            throw ValidationException::withMessages(['contract' => ['Periode kontrak berikutnya overlap dengan record kontrak lain untuk Squad yang sama. Review history sebelum transisi.']]);
        }
    }

    private function recordLifecycleEvent(object $row, string $eventType, ?string $fromStage, ?string $toStage, ?User $actor, array $metadata = [], bool $closeCurrentStage = false): void
    {
        if (! Schema::hasTable('HR_contract_lifecycle_events')) return;
        $group = $row->lifecycle_group ?: $this->resolveGroup($row);
        $stageForSnapshot = $closeCurrentStage ? $fromStage : ($toStage ?: $fromStage);
        $squad = $row->squad_id ? DB::table('HR_squads')->where('id', $row->squad_id)->first() : null;
        $outletName = $row->outlet_id ? DB::table('outlets')->where('id', $row->outlet_id)->value('name') : null;

        DB::table('HR_contract_lifecycle_events')->insert([
            'id' => (string) Str::ulid(),
            'contract_id' => (string) $row->id,
            'squad_id' => $row->squad_id,
            'event_type' => $eventType,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'group_code' => $group,
            'contract_kind' => $stageForSnapshot ? $this->contractKind($stageForSnapshot, $group) : null,
            'duration_days' => $stageForSnapshot ? $this->durationFor($stageForSnapshot, $group) : null,
            'stage_start_date' => $this->date($row->start_date),
            'stage_end_date' => $this->date($row->end_date),
            'next_due_at' => $this->nextDue($row->end_date),
            'contract_no_snapshot' => $row->contract_no,
            'contract_type_snapshot' => $row->contract_type,
            'outlet_id' => $row->outlet_id,
            'outlet_name_snapshot' => $outletName,
            'division_name_snapshot' => $row->division_name ?: ($squad->division_name ?? null),
            'position_name_snapshot' => $row->position_name ?: ($squad->position_name ?? null),
            'salary_tier_snapshot' => $squad->salary_tier_name ?? null,
            'nominal_fee_snapshot' => (float) ($squad->basic_salary ?? 0),
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'actor_user_id' => $actor?->id,
            'event_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordLegacyEvent(object $row, string $eventType, ?User $actor, array $metadata, ?string $effectiveDate = null): void
    {
        if (! Schema::hasTable('HR_contract_events')) return;
        DB::table('HR_contract_events')->insert([
            'id' => (string) Str::ulid(),
            'contract_id' => (string) $row->id,
            'document_id' => null,
            'assignment_id' => $row->assignment_id,
            'event_type' => $eventType,
            'effective_date' => $effectiveDate ?: $this->date($row->start_date),
            'note' => $eventType === 'contract_lifecycle_transition' ? 'Transisi lifecycle kontrak.' : 'Normalisasi stage lifecycle kontrak.',
            'before_snapshot' => null,
            'after_snapshot' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?: $actor?->username ?: $actor?->nisj,
            'event_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syncSquadLegacy(mixed $squadId, string $stage, string $start, ?string $end, object $row): void
    {
        if (! $squadId || ! Schema::hasTable('HR_squads')) return;
        DB::table('HR_squads')->where('id', $squadId)->update([
            'contract_type' => $stage,
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            // Penempatan tidak diubah oleh lifecycle kontrak; jaga master assignment existing.
            'updated_at' => now(),
        ]);
    }

    private function snapshot(object $row): array
    {
        return [
            'contract_id' => (string) $row->id,
            'contract_no' => $row->contract_no,
            'contract_type' => $row->contract_type,
            'lifecycle_stage' => $row->lifecycle_stage,
            'lifecycle_group' => $row->lifecycle_group,
            'status' => $row->status,
            'start_date' => $this->date($row->start_date),
            'end_date' => $this->date($row->end_date),
            'next_contract_due_at' => $this->date($row->next_contract_due_at),
        ];
    }

    private function identityFromRow(object $row): array
    {
        return [
            'squad_id' => $row->squad_id ? (int) $row->squad_id : null,
            'nisj' => (string) ($row->nisj ?? ''),
            'full_name' => (string) ($row->full_name ?? '-'),
            'assignment_label' => (string) ($row->assignment_label ?? ''),
            'outlet_id' => (string) ($row->outlet_id ?? ''),
            'outlet_name' => (string) ($row->outlet_name ?? '-'),
            'division_name' => (string) ($row->division_name ?: $row->squad_division_name ?: ''),
            'position_name' => (string) ($row->position_name ?: $row->squad_position_name ?: ''),
            'salary_tier_name' => (string) ($row->salary_tier_name ?? ''),
            'basic_salary' => (float) ($row->basic_salary ?? 0),
            'lifecycle_group' => $row->lifecycle_group ?: $this->resolveGroup($row),
        ];
    }

    private function stageFromEvent(object $event): array
    {
        return [
            'stage' => strtoupper((string) $event->from_stage),
            'contract_kind' => (string) ($event->contract_kind ?? ''),
            'start_date' => $this->date($event->stage_start_date),
            'end_date' => $this->date($event->stage_end_date),
            'renewal_date' => $this->date($event->next_due_at),
            'salary_tier_name' => (string) ($event->salary_tier_snapshot ?? ''),
            'nominal_fee' => (float) ($event->nominal_fee_snapshot ?? 0),
            'status' => 'DONE',
            'contract_no' => (string) ($event->contract_no_snapshot ?? ''),
            'group' => (string) ($event->group_code ?? ''),
        ];
    }

    private function stageFromCurrent(object $row): array
    {
        $stage = strtoupper((string) $row->lifecycle_stage);
        $group = $row->lifecycle_group ?: $this->resolveGroup($row);
        return [
            'stage' => $stage,
            'contract_kind' => $this->contractKind($stage, $group),
            'start_date' => $this->date($row->start_date),
            'end_date' => $this->date($row->end_date),
            'renewal_date' => $stage === 'PKWTT' ? null : ($this->date($row->next_contract_due_at) ?: $this->nextDue($row->end_date)),
            'salary_tier_name' => (string) ($row->salary_tier_name ?? ''),
            'nominal_fee' => (float) ($row->basic_salary ?? 0),
            'status' => strtoupper((string) ($row->status ?: 'ACTIVE')),
            'contract_no' => (string) ($row->contract_no ?? ''),
            'group' => $group,
        ];
    }

    private function latestStage(array $stages): ?string
    {
        $latest = null;
        foreach (self::STAGES as $stage) if (! empty($stages[$stage])) $latest = $stage;
        return $latest;
    }

    private function emptyPage(): array
    {
        return ['items' => [], 'summary' => ['total' => 0, 'needs_review' => 0, 'pkwtt' => 0, 'overdue' => 0, 'due_30' => 0], 'meta' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
    }

    private function emptyRecapSummary(): array
    {
        return ['total_people' => 0, 'needs_review' => 0, 'pkwtt' => 0, 'finite_contract' => 0];
    }

    private function date(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse((string) $value, 'Asia/Jakarta')->toDateString(); }
        catch (\Throwable) { return substr((string) $value, 0, 10) ?: null; }
    }

    private function dateLabel(string $value): string
    {
        try { return Carbon::parse($value, 'Asia/Jakarta')->locale('id')->translatedFormat('d F Y'); }
        catch (\Throwable) { return $value; }
    }
}
