<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class HrPayrollOvertimeIntegrationI07Service
{
    private const SYNC_VERSION = 'I07_OVERTIME_PAYROLL_V1';
    private const PER_PAGE = [10, 25, 50, 100, 500];

    public function __construct(private readonly HrPayrollService $payroll) {}

    /**
     * Payroll projection that keeps the existing HrPayrollService as canonical
     * for attendance/salary rules, then overlays completed I06 overtime.
     */
    public function projection(Request $request): array
    {
        if (! Schema::hasTable('HR_overtimes')) {
            return $this->payroll->projection($request);
        }

        $rows = $this->collectBaseProjectionRows($request);
        if ($rows->isEmpty()) {
            $base = $this->payroll->projection($request);
            $base['meta'] = array_merge($base['meta'] ?? [], [
                'overtime_source' => 'HR_overtimes',
                'overtime_status' => 'completed',
                'overtime_sync_version' => self::SYNC_VERSION,
            ]);
            return $base;
        }

        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $sourceGroups = $this->overtimeGroupsForRows($rows, $from, $to);

        $rows = $rows->map(function (array $row) use ($sourceGroups): array {
            $key = $this->key((string) ($row['employee_id'] ?? ''), $row['outlet_id'] ?? null);
            return $this->applyProjectionOvertime($row, $sourceGroups->get($key, collect()));
        })->values();

        $sortBy = (string) $request->query('sort_by', 'full_name');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = [
            'full_name', 'nisj', 'company_code', 'outlet_name', 'position',
            'work_days', 'work_minutes', 'late_minutes', 'alpha_days', 'field_duty_days',
            'overtime_minutes', 'overtime_hours', 'overtime_pay', 'total_net',
        ];
        if (! in_array($sortBy, $allowedSort, true)) $sortBy = 'full_name';
        $rows = $rows->sortBy(
            fn (array $row) => is_numeric(data_get($row, $sortBy))
                ? (float) data_get($row, $sortBy)
                : mb_strtolower((string) data_get($row, $sortBy, '')),
            SORT_NATURAL | SORT_FLAG_CASE,
            $sortDir === 'desc'
        )->values();

        $summary = $this->projectionSummary($rows);
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE, true)) $perPage = 25;
        $total = $rows->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query('page', 1), $last));

        return [
            'items' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $last,
            ],
            'meta' => [
                'from' => $from,
                'to' => $to,
                'company_code' => $request->query('company_code'),
                'outlet_id' => $request->query('outlet_id'),
                'overtime_source' => 'HR_overtimes',
                'overtime_status' => 'completed',
                'overtime_sync_version' => self::SYNC_VERSION,
            ],
        ];
    }

    /**
     * Synchronize a draft cutoff from completed overtime. Final/submitted cutoffs
     * are intentionally frozen and are not changed by attendance corrections.
     */
    public function syncCutoffDraft(
        HrPayrollCutoff $cutoff,
        ?string $actorUserId = null,
        string $reason = 'AUTO_SYNC'
    ): HrPayrollCutoff {
        if (! Schema::hasTable('HR_overtimes')) return $cutoff->fresh();

        return DB::transaction(function () use ($cutoff, $actorUserId, $reason): HrPayrollCutoff {
            $locked = HrPayrollCutoff::query()->lockForUpdate()->findOrFail($cutoff->id);
            if ((string) $locked->status !== 'draft') return $locked->fresh();

            $slips = $locked->slips()->lockForUpdate()->get();
            if ($slips->isEmpty()) return $locked->fresh();

            $from = $locked->period_from->format('Y-m-d');
            $to = $locked->period_to->format('Y-m-d');
            $sources = $this->sourceRows(
                $slips->pluck('employee_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all(),
                $from,
                $to
            );
            $groups = $sources->groupBy(fn ($row) => $this->key((string) $row->employee_id, $row->outlet_id));

            $changedSlips = 0;
            $totalMinutes = 0;
            $totalSourceAmount = 0.0;
            $totalSourceRows = 0;

            foreach ($slips as $slip) {
                $key = $this->key((string) $slip->employee_id, $slip->outlet_id_snapshot);
                /** @var Collection<int, object> $rows */
                $rows = $groups->get($key, collect());
                $minutes = (int) $rows->sum(fn ($row) => (int) $row->overtime_minutes);
                $sourceAmount = round((float) $rows->sum(fn ($row) => (float) $row->amount_snapshot), 2);
                $effectiveRate = $this->effectiveRate($rows, $minutes, $sourceAmount, (float) $slip->overtime_rate);

                $before = [
                    'minutes' => (int) $slip->overtime_minutes,
                    'rate' => round((float) $slip->overtime_rate, 2),
                    'pay' => round((float) $slip->overtime_pay, 2),
                ];

                $slip->overtime_minutes = $minutes;
                $slip->overtime_rate = $effectiveRate;
                // Existing overtime_hours_override is a deliberate HR manual override.
                // Do not clear it during automatic attendance synchronization.
                $slip = $this->payroll->recalculateAndSaveSlip($slip);

                if ($slip->overtime_hours_override === null) {
                    $slip = $this->applyExactOvertimeAmount($slip, $sourceAmount);
                }

                $this->replaceSnapshots($locked, $slip, $rows);

                $after = [
                    'minutes' => (int) $slip->overtime_minutes,
                    'rate' => round((float) $slip->overtime_rate, 2),
                    'pay' => round((float) $slip->overtime_pay, 2),
                ];
                if ($before !== $after) $changedSlips++;

                $totalMinutes += $minutes;
                $totalSourceAmount += $sourceAmount;
                $totalSourceRows += $rows->count();
            }

            $this->payroll->refreshSummary($locked);
            $locked->refresh();

            if ($changedSlips > 0) {
                $this->auditSync($locked, $actorUserId, $reason, [
                    'changed_slips' => $changedSlips,
                    'source_rows' => $totalSourceRows,
                    'overtime_minutes' => $totalMinutes,
                    'overtime_hours' => round($totalMinutes / 60, 2),
                    'source_amount' => round($totalSourceAmount, 2),
                    'sync_version' => self::SYNC_VERSION,
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Re-apply exact snapshot amounts after another payroll workflow recalculation
     * (adjustment/BPJS import). This never re-reads current attendance data, so a
     * submitted/finalized cutoff remains historically frozen.
     */
    public function stabilizeCutoffFromSnapshots(HrPayrollCutoff $cutoff): void
    {
        if (! Schema::hasTable('HR_payroll_overtime_snapshots')) return;

        $slips = $cutoff->slips()->get();
        if ($slips->isEmpty()) return;

        $totals = DB::table('HR_payroll_overtime_snapshots')
            ->where('cutoff_id', (string) $cutoff->id)
            ->whereIn('slip_id', $slips->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->selectRaw('slip_id, SUM(amount_snapshot) as source_amount')
            ->groupBy('slip_id')
            ->get()
            ->keyBy(fn ($row) => (string) $row->slip_id);

        foreach ($slips as $slip) {
            if ($slip->overtime_hours_override !== null) continue;
            $row = $totals->get((string) $slip->id);
            if (! $row) continue;
            $this->applyExactOvertimeAmount($slip, round((float) $row->source_amount, 2));
        }
        $this->payroll->refreshSummary($cutoff);
    }

    public function decorateCutoffDetail(array $payload): array
    {
        $items = collect($payload['items'] ?? []);
        if ($items->isEmpty() || ! Schema::hasTable('HR_payroll_overtime_snapshots')) {
            $payload['summary'] = array_merge($payload['summary'] ?? [], [
                'overtime_minutes' => (int) $items->sum('overtime_minutes'),
                'overtime_hours' => round((int) $items->sum('overtime_minutes') / 60, 2),
                'overtime_pay' => round((float) $items->sum('overtime_pay'), 2),
            ]);
            return $payload;
        }

        $ids = $items->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        $snapshots = DB::table('HR_payroll_overtime_snapshots')
            ->whereIn('slip_id', $ids)
            ->orderBy('business_date')
            ->get([
                'slip_id', 'overtime_id', 'business_date', 'source',
                'overtime_minutes', 'overtime_rate_snapshot', 'amount_snapshot',
            ])
            ->groupBy(fn ($row) => (string) $row->slip_id);

        $payload['items'] = $items->map(function (array $item) use ($snapshots): array {
            $rows = $snapshots->get((string) ($item['id'] ?? ''), collect());
            $details = $rows->map(fn ($row) => [
                'overtime_id' => (string) $row->overtime_id,
                'business_date' => (string) $row->business_date,
                'source' => (string) $row->source,
                'overtime_minutes' => (int) $row->overtime_minutes,
                'overtime_hours' => round((int) $row->overtime_minutes / 60, 2),
                'overtime_rate' => round((float) $row->overtime_rate_snapshot, 2),
                'amount' => round((float) $row->amount_snapshot, 2),
            ])->values()->all();
            $item['overtime_source_count'] = count($details);
            $item['overtime_source_details'] = $details;
            $item['overtime_source_amount'] = round((float) $rows->sum(fn ($row) => (float) $row->amount_snapshot), 2);
            return $item;
        })->values();

        $detailItems = collect($payload['items']);
        $payload['summary'] = array_merge($payload['summary'] ?? [], [
            'overtime_minutes' => (int) $detailItems->sum('overtime_minutes'),
            'overtime_hours' => round((int) $detailItems->sum('overtime_minutes') / 60, 2),
            'overtime_pay' => round((float) $detailItems->sum('overtime_pay'), 2),
        ]);
        $payload['overtime_meta'] = [
            'source' => 'HR_overtimes',
            'eligible_status' => 'completed',
            'sync_version' => self::SYNC_VERSION,
        ];
        return $payload;
    }

    private function collectBaseProjectionRows(Request $request): Collection
    {
        $all = collect();
        $page = 1;
        $last = 1;
        do {
            $params = $request->query->all();
            $params['page'] = $page;
            $params['per_page'] = 500;
            $sub = Request::create('/internal-payroll-projection-i07', 'GET', $params);
            $sub->setUserResolver(fn () => $request->user());
            foreach ($request->headers->all() as $key => $values) {
                foreach ($values as $value) $sub->headers->set($key, $value);
            }
            $payload = $this->payroll->projection($sub);
            $all = $all->concat($payload['items'] ?? []);
            $last = max(1, (int) data_get($payload, 'pagination.last_page', 1));
            $page++;
        } while ($page <= $last);
        return $all->values();
    }

    private function overtimeGroupsForRows(Collection $rows, string $from, string $to): Collection
    {
        $employeeIds = $rows->pluck('employee_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        return $this->sourceRows($employeeIds, $from, $to)
            ->groupBy(fn ($row) => $this->key((string) $row->employee_id, $row->outlet_id));
    }

    private function sourceRows(array $employeeIds, string $from, string $to): Collection
    {
        if ($employeeIds === [] || ! Schema::hasTable('HR_overtimes')) return collect();
        return DB::table('HR_overtimes')
            ->where('status', 'completed')
            ->whereBetween('business_date', [$from, $to])
            ->whereIn('employee_id', $employeeIds)
            ->get([
                'id', 'employee_id', 'outlet_id', 'business_date', 'source',
                'overtime_minutes', 'overtime_rate_snapshot', 'amount_snapshot',
            ]);
    }

    private function applyProjectionOvertime(array $row, Collection $sources): array
    {
        $minutes = (int) $sources->sum(fn ($source) => (int) $source->overtime_minutes);
        $amount = round((float) $sources->sum(fn ($source) => (float) $source->amount_snapshot), 2);
        $currentPay = round((float) ($row['overtime_pay'] ?? 0), 2);
        $rate = $this->effectiveRate($sources, $minutes, $amount, (float) ($row['overtime_rate'] ?? 0));
        $baseNonWage = (float) ($row['total_non_wage'] ?? 0) - $currentPay;
        $baseNet = (float) ($row['total_net'] ?? 0) - $currentPay;

        $row['overtime_minutes'] = $minutes;
        $row['overtime_hours'] = round($minutes / 60, 2);
        $row['overtime_rate'] = $rate;
        $row['overtime_pay'] = $amount;
        $row['overtime_source_count'] = $sources->count();
        $row['overtime_source_amount'] = $amount;
        $row['total_non_wage'] = round($baseNonWage + $amount, 2);
        $row['total_net'] = round($baseNet + $amount, 2);
        return $row;
    }

    private function effectiveRate(Collection $sources, int $minutes, float $amount, float $fallback): float
    {
        if ($minutes <= 0) return round(max(0, $fallback), 2);
        $rates = $sources->pluck('overtime_rate_snapshot')->map(fn ($rate) => round((float) $rate, 2))->unique()->values();
        if ($rates->count() === 1) return round(max(0, (float) $rates->first()), 2);
        return round(max(0, $amount / ($minutes / 60)), 2);
    }

    private function projectionSummary(Collection $rows): array
    {
        $minutes = (int) $rows->sum('overtime_minutes');
        return [
            'employees' => $rows->pluck('employee_id')->unique()->count(),
            'rows' => $rows->count(),
            'work_days' => (int) $rows->sum('work_days'),
            'work_minutes' => (int) $rows->sum('work_minutes'),
            'overtime_minutes' => $minutes,
            'overtime_hours' => round($minutes / 60, 2),
            'overtime_pay' => round((float) $rows->sum('overtime_pay'), 2),
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'alpha_days' => (int) $rows->sum('alpha_days'),
            'field_duty_days' => (int) $rows->sum('field_duty_days'),
            'gross_wage' => round((float) $rows->sum('gross_wage'), 2),
            'total_non_wage' => round((float) $rows->sum('total_non_wage'), 2),
            'total_deduction' => round((float) $rows->sum('total_deduction'), 2),
            'total_net' => round((float) $rows->sum('total_net'), 2),
        ];
    }

    private function replaceSnapshots(HrPayrollCutoff $cutoff, HrPayrollSlip $slip, Collection $sources): void
    {
        if (! Schema::hasTable('HR_payroll_overtime_snapshots')) return;
        DB::table('HR_payroll_overtime_snapshots')->where('slip_id', (string) $slip->id)->delete();
        if ($sources->isEmpty()) return;

        $now = now();
        $rows = $sources->map(fn ($source) => [
            'id' => (string) Str::ulid(),
            'cutoff_id' => (string) $cutoff->id,
            'slip_id' => (string) $slip->id,
            'overtime_id' => (string) $source->id,
            'employee_id' => (string) $source->employee_id,
            'outlet_id' => $source->outlet_id ? (string) $source->outlet_id : null,
            'business_date' => (string) $source->business_date,
            'source' => (string) $source->source,
            'overtime_minutes' => (int) $source->overtime_minutes,
            'overtime_rate_snapshot' => round((float) $source->overtime_rate_snapshot, 2),
            'amount_snapshot' => round((float) $source->amount_snapshot, 2),
            'sync_version' => self::SYNC_VERSION,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        DB::table('HR_payroll_overtime_snapshots')->insert($rows);
    }

    private function applyExactOvertimeAmount(HrPayrollSlip $slip, float $exactAmount): HrPayrollSlip
    {
        $exactAmount = round(max(0, $exactAmount), 2);
        $current = round((float) $slip->overtime_pay, 2);
        $delta = round($exactAmount - $current, 2);
        if (abs($delta) < 0.005) return $slip;

        $slip->overtime_pay = $exactAmount;
        $slip->total_non_wage = round((float) $slip->total_non_wage + $delta, 2);
        $slip->total_net = round((float) $slip->total_net + $delta, 2);
        $slip->save();
        return $slip->fresh();
    }

    private function auditSync(HrPayrollCutoff $cutoff, ?string $actorUserId, string $reason, array $metadata): void
    {
        if (! Schema::hasTable('HR_payroll_cutoff_events')) return;
        DB::table('HR_payroll_cutoff_events')->insert([
            'id' => (string) Str::ulid(),
            'cutoff_id' => (string) $cutoff->id,
            'event' => 'OVERTIME_SYNC_I07',
            'from_status' => (string) $cutoff->status,
            'to_status' => (string) $cutoff->status,
            'actor_user_id' => $actorUserId ?: null,
            'finance_posting_id' => $cutoff->finance_posting_id ?: null,
            'metadata' => json_encode(array_merge($metadata, ['reason' => $reason]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function key(string $employeeId, mixed $outletId): string
    {
        return $employeeId.'|'.trim((string) ($outletId ?? ''));
    }
}
