<?php

namespace App\Services\HumanResource;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HrCrossModuleReconciliationService
{
    public function run(int $sampleLimit = 20): array
    {
        $sampleLimit = max(1, min($sampleLimit, 100));
        $issues = [];

        $this->guard($issues, fn () => $this->identity($issues, $sampleLimit), 'identity.runtime_error');
        $this->guard($issues, fn () => $this->assignmentContract($issues, $sampleLimit), 'assignment_contract.runtime_error');
        $this->guard($issues, fn () => $this->attendanceLeave($issues, $sampleLimit), 'attendance_leave.runtime_error');
        $this->guard($issues, fn () => $this->punishment($issues, $sampleLimit), 'punishment.runtime_error');
        $this->guard($issues, fn () => $this->recruitment($issues, $sampleLimit), 'recruitment.runtime_error');
        $this->guard($issues, fn () => $this->kpiBonus($issues, $sampleLimit), 'kpi_bonus.runtime_error');
        $this->guard($issues, fn () => $this->payrollFinance($issues, $sampleLimit), 'payroll_finance.runtime_error');
        $this->guard($issues, fn () => $this->schedulerBacklog($issues, $sampleLimit), 'scheduler.runtime_error');

        $counts = [
            'critical' => collect($issues)->where('severity', 'critical')->sum('count'),
            'warning' => collect($issues)->where('severity', 'warning')->sum('count'),
            'info' => collect($issues)->where('severity', 'info')->sum('count'),
            'checks_with_findings' => collect($issues)->where('count', '>', 0)->count(),
        ];

        return [
            'dry_run' => true,
            'generated_at' => now()->toIso8601String(),
            'counts' => $counts,
            'issues' => $issues,
        ];
    }

    private function identity(array &$issues, int $limit): void
    {
        if (Schema::hasTable('employees')) {
            $this->duplicateNormalized($issues, 'identity.duplicate_employee_nisj', 'critical', 'employees', 'nisj', 'Employee mempunyai NISJ ganda.', $limit);
            if (Schema::hasColumn('employees', 'user_id')) {
                $q = DB::table('employees')->select('user_id', DB::raw('COUNT(*) AS total'))->whereNotNull('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1');
                $this->fromGrouped($issues, 'identity.duplicate_employee_user', 'critical', 'Satu user_id terhubung ke lebih dari satu employee.', $q, $limit);
            }
        }

        if (Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', 'user_id') && Schema::hasTable('users')) {
            $q = DB::table('HR_squads as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')->whereNull('s.deleted_at')->whereNotNull('s.user_id')->whereNull('u.id');
            $this->fromQuery($issues, 'identity.squad_user_missing', 'critical', 'Data Squad menunjuk user yang tidak ditemukan.', $q, ['s.id','s.nisj','s.full_name','s.user_id'], $limit);

            if (Schema::hasColumn('users', 'is_active')) {
                $q = DB::table('HR_squads as s')->join('users as u', 'u.id', '=', 's.user_id')->whereNull('s.deleted_at')->whereRaw("LOWER(COALESCE(s.status,'')) = 'active'")->where('u.is_active', false);
                $this->fromQuery($issues, 'identity.active_squad_inactive_user', 'warning', 'Squad aktif masih terhubung ke akun login inactive.', $q, ['s.id','s.nisj','s.full_name','s.user_id'], $limit);
            }
        }

        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'user_id') && Schema::hasTable('users')) {
            $q = DB::table('employees as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->whereNotNull('e.user_id')->whereNull('u.id');
            $this->fromQuery($issues, 'identity.employee_user_missing', 'critical', 'Employee menunjuk user yang tidak ditemukan.', $q, ['e.id','e.nisj','e.full_name','e.user_id'], $limit);
        }
    }

    private function assignmentContract(array &$issues, int $limit): void
    {
        if (Schema::hasTable('assignments')) {
            $active = DB::table('assignments')->select('employee_id', DB::raw('COUNT(*) AS total'))
                ->whereNotNull('employee_id')->where('is_primary', true)
                ->where(function ($q) { $q->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('inactive','cancelled')"); })
                ->where(function ($q) { $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()); })
                ->groupBy('employee_id')->havingRaw('COUNT(*) > 1');
            $this->fromGrouped($issues, 'assignment.multiple_active_primary', 'critical', 'Employee memiliki lebih dari satu primary assignment aktif.', $active, $limit);

            $q = DB::table('assignments')->where('is_primary', true)->whereDate('end_date', '<', now()->toDateString())
                ->where(function ($x) { $x->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('inactive','cancelled')"); });
            $this->fromQuery($issues, 'assignment.expired_still_primary', 'warning', 'Assignment melewati end_date tetapi masih primary/aktif.', $q, ['id','employee_id','outlet_id','start_date','end_date','status'], $limit);
        }

        if (Schema::hasTable('employees') && Schema::hasTable('assignments') && Schema::hasColumn('employees', 'assignment_id')) {
            $q = DB::table('employees as e')->leftJoin('assignments as a', 'a.id', '=', 'e.assignment_id')->whereNotNull('e.assignment_id')
                ->where(function ($x) { $x->whereNull('a.id')->orWhereColumn('a.employee_id', '<>', 'e.id'); });
            $this->fromQuery($issues, 'assignment.employee_pointer_mismatch', 'critical', 'employees.assignment_id tidak menunjuk assignment milik employee yang sama.', $q, ['e.id','e.nisj','e.full_name','e.assignment_id'], $limit);
        }

        if (Schema::hasTable('HR_contracts')) {
            $q = DB::table('HR_contracts')->whereNull('deleted_at')->where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<', now()->toDateString());
            $this->fromQuery($issues, 'contract.active_past_end_date', 'warning', 'Kontrak aktif sudah melewati tanggal berakhir; scheduler belum menandai expired.', $q, ['id','employee_id','contract_no','contract_type','end_date'], $limit);

            $q = DB::table('HR_contracts')->select('employee_id', DB::raw('COUNT(*) AS total'))->whereNull('deleted_at')->where('status', 'active')->whereNotNull('employee_id')->groupBy('employee_id')->havingRaw('COUNT(*) > 1');
            $this->fromGrouped($issues, 'contract.multiple_active', 'critical', 'Employee mempunyai lebih dari satu kontrak aktif.', $q, $limit);
        }

        if (Schema::hasTable('HR_contract_documents')) {
            $q = DB::table('HR_contract_documents')->whereNull('deleted_at')->where('status', 'approved')->where('effect_status', 'pending_effective')
                ->whereNotNull('effective_date')->whereDate('effective_date', '<=', now()->toDateString());
            $this->fromQuery($issues, 'contract.effect_overdue', 'critical', 'SK approved sudah melewati TMT tetapi effect belum diterapkan.', $q, ['id','contract_id','document_no','document_type','effective_date'], $limit);
        }
    }

    private function attendanceLeave(array &$issues, int $limit): void
    {
        if (Schema::hasTable('HR_attendances')) {
            $q = DB::table('HR_attendances')->select('user_id','business_date', DB::raw('COUNT(*) AS total'))->groupBy('user_id','business_date')->havingRaw('COUNT(*) > 1');
            $this->fromGrouped($issues, 'attendance.duplicate_user_date', 'critical', 'Attendance ganda untuk user dan business_date yang sama.', $q, $limit);

            $q = DB::table('HR_attendances')->whereNotNull('checkout_at')->whereNotNull('checkin_at')->whereColumn('checkout_at', '<=', 'checkin_at')->where('record_status', '<>', 'cancelled');
            $this->fromQuery($issues, 'attendance.invalid_interval', 'critical', 'Checkout lebih awal/sama dengan checkin.', $q, ['id','user_id','employee_id','business_date','checkin_at','checkout_at'], $limit);
        }

        if (Schema::hasTable('HR_leave_requests')) {
            $q = DB::table('HR_leave_requests')->whereColumn('end_date', '<', 'start_date');
            $this->fromQuery($issues, 'leave.invalid_date_range', 'critical', 'Izin/Cuti mempunyai end_date sebelum start_date.', $q, ['id','employee_id','type','start_date','end_date','status'], $limit);

            if (Schema::hasTable('HR_leave_quota_ledgers')) {
                $q = DB::table('HR_leave_requests as l')->leftJoin('HR_leave_quota_ledgers as q', 'q.leave_request_id', '=', 'l.id')
                    ->where('l.type', 'cuti')->where('l.status', 'approved')->whereNull('q.id');
                $this->fromQuery($issues, 'leave.approved_cuti_without_quota_ledger', 'warning', 'Cuti approved belum mempunyai quota ledger.', $q, ['l.id','l.employee_id','l.start_date','l.end_date'], $limit);
            }
        }
    }

    private function punishment(array &$issues, int $limit): void
    {
        if (Schema::hasTable('HR_violations')) {
            $this->duplicateNormalized($issues, 'punishment.duplicate_violation_fingerprint', 'critical', 'HR_violations', 'fingerprint', 'Duplicate violation fingerprint terdeteksi.', $limit);
        }
        if (Schema::hasTable('HR_warning_letters')) {
            $q = DB::table('HR_warning_letters')->select('employee_id','sp_level', DB::raw('COUNT(*) AS total'))->whereNull('deleted_at')->where('status','approved')->groupBy('employee_id','sp_level')->havingRaw('COUNT(*) > 1');
            $this->fromGrouped($issues, 'punishment.duplicate_approved_sp_level', 'critical', 'Employee mempunyai SP approved ganda pada level yang sama.', $q, $limit);

            if (Schema::hasTable('HR_squads') && Schema::hasTable('users') && Schema::hasColumn('HR_squads', 'user_id') && Schema::hasColumn('users', 'is_active')) {
                $q = DB::table('HR_warning_letters as w')->join('HR_squads as s','s.id','=','w.squad_id')->join('users as u','u.id','=','s.user_id')
                    ->whereNull('w.deleted_at')->where('w.status','approved')->where('w.sp_level',3)->where('u.is_active',true);
                $this->fromQuery($issues, 'punishment.sp3_user_still_active', 'critical', 'SP-3 approved tetapi akun user masih aktif.', $q, ['w.id','w.employee_id','w.squad_id','w.effective_date','s.nisj','u.id as user_id'], $limit);
            }

            if (Schema::hasColumn('HR_warning_letters', 'termination_contract_document_id')) {
                $q = DB::table('HR_warning_letters')->whereNull('deleted_at')->where('status','approved')->where('sp_level',3)->whereNull('termination_contract_document_id');
                $this->fromQuery($issues, 'punishment.sp3_missing_termination_sk', 'critical', 'SP-3 approved belum mempunyai draft SK Pemutusan.', $q, ['id','employee_id','squad_id','effective_date'], $limit);
            }
        }
    }

    private function recruitment(array &$issues, int $limit): void
    {
        if (Schema::hasTable('HR_hiring_conversions')) {
            $this->duplicateNormalized($issues, 'recruitment.duplicate_conversion_key', 'critical', 'HR_hiring_conversions', 'conversion_key', 'Duplicate hiring conversion key.', $limit);
            $q = DB::table('HR_hiring_conversions')->select('application_id', DB::raw('COUNT(*) AS total'))->groupBy('application_id')->havingRaw('COUNT(*) > 1');
            $this->fromGrouped($issues, 'recruitment.duplicate_conversion_application', 'critical', 'Satu application mempunyai lebih dari satu hiring conversion.', $q, $limit);

            $q = DB::table('HR_hiring_conversions')->where('status','completed')->where(function ($x) {
                $x->whereNull('assigned_nisj')->orWhereNull('squad_id')->orWhereNull('employee_id')->orWhereNull('contract_id');
            });
            $this->fromQuery($issues, 'recruitment.completed_conversion_incomplete', 'critical', 'Hiring conversion completed tetapi output identity/contract tidak lengkap.', $q, ['id','application_id','hire_type','assigned_nisj','operational_user_id','squad_id','employee_id','contract_id'], $limit);
        }

        if (Schema::hasTable('HR_career_accounts') && Schema::hasTable('HR_applications')) {
            $q = DB::table('HR_career_accounts as c')->join('HR_applications as a','a.career_account_id','=','c.id')
                ->whereNotNull('c.application_blocked_at')->whereNotIn('a.stage',['rejected_all','rejected_partial']);
            $this->fromQuery($issues, 'recruitment.blocked_account_open_application', 'warning', 'Career account diblokir tetapi masih mempunyai application aktif.', $q, ['c.id','c.nik','c.full_name','a.id as application_id','a.stage'], $limit);
        }
    }

    private function kpiBonus(array &$issues, int $limit): void
    {
        if (Schema::hasTable('HR_kpi_daily_reviews') && Schema::hasTable('HR_kpi_daily_entries')) {
            $q = DB::table('HR_kpi_daily_reviews as r')->leftJoin('HR_kpi_daily_entries as e','e.review_id','=','r.id')->where('r.status','locked')
                ->select('r.id','r.outlet_id','r.review_date',DB::raw('COUNT(e.id) AS entry_count'))
                ->groupBy('r.id','r.outlet_id','r.review_date')->havingRaw('COUNT(e.id) = 0');
            $this->fromGrouped($issues, 'kpi.locked_review_without_entries', 'warning', 'KPI daily review locked tanpa entry Squad.', $q, $limit);
        }

        if (Schema::hasTable('HR_bonus_projections')) {
            $q = DB::table('HR_bonus_projections')->where('status','finalized')->where(function ($x) {
                $x->whereNull('finalized_snapshot')->orWhereNull('calculation_hash')->orWhereNull('finance_posting_id');
            });
            $this->fromQuery($issues, 'bonus.finalized_snapshot_incomplete', 'critical', 'Cutoff Bonus finalized tanpa immutable snapshot/hash/Finance link.', $q, ['id','kpi_period_id','outlet_id','period_from','period_to','finance_posting_id'], $limit);

            if (Schema::hasTable('HR_bonus_projection_lines')) {
                $q = DB::table('HR_bonus_projections as p')->leftJoin('HR_bonus_projection_lines as l','l.projection_id','=','p.id')->where('p.status','finalized')
                    ->select('p.id','p.total_payout',DB::raw('COALESCE(SUM(l.bonus_payout),0) AS line_total'))
                    ->groupBy('p.id','p.total_payout')->havingRaw('ABS(COALESCE(SUM(l.bonus_payout),0) - p.total_payout) > 0.01');
                $this->fromGrouped($issues, 'bonus.line_total_mismatch', 'critical', 'Total payout projection tidak sama dengan jumlah payout line.', $q, $limit);
            }
        }
    }

    private function payrollFinance(array &$issues, int $limit): void
    {
        if (! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('finance_payroll_posting_inbox')) return;

        if (Schema::hasColumn('HR_payroll_cutoffs', 'finance_posting_id')) {
            $q = DB::table('HR_payroll_cutoffs as c')->leftJoin('finance_payroll_posting_inbox as f','f.id','=','c.finance_posting_id')
                ->whereIn('c.status',['submitted','finance_processing','finalized'])->whereNull('f.id');
            $this->fromQuery($issues, 'payroll.missing_finance_bridge', 'critical', 'Payroll cutoff workflow membutuhkan Finance posting tetapi link tidak ditemukan.', $q, ['c.id','c.code','c.status','c.finance_posting_id'], $limit);

            $q = DB::table('HR_payroll_cutoffs as c')->join('finance_payroll_posting_inbox as f','f.id','=','c.finance_posting_id')->where('c.status','finalized')
                ->whereNotIn('f.status',['APPROVED','ACCRUED','PARTIALLY_PAID','PAID']);
            $this->fromQuery($issues, 'payroll.finalized_finance_not_approved', 'critical', 'Payroll cutoff finalized tetapi Finance posting belum approved/accrued/paid.', $q, ['c.id','c.code','c.status','f.id as finance_id','f.status as finance_status'], $limit);
        }

        if (Schema::hasTable('HR_payroll_slips')) {
            $q = DB::table('HR_payroll_cutoffs as c')->leftJoin('HR_payroll_slips as s','s.cutoff_id','=','c.id')->groupBy('c.id','c.snapshot_count','c.snapshot_total_net')
                ->havingRaw('COUNT(s.id) <> c.snapshot_count OR ABS(COALESCE(SUM(s.total_net),0) - c.snapshot_total_net) > 0.01');
            $this->fromQuery($issues, 'payroll.snapshot_totals_mismatch', 'critical', 'Snapshot count/net cutoff tidak sama dengan slip payroll.', $q, ['c.id','c.code','c.snapshot_count','c.snapshot_total_net',DB::raw('COUNT(s.id) AS slip_count'),DB::raw('COALESCE(SUM(s.total_net),0) AS slip_total')], $limit);
        }

        if (Schema::hasTable('HR_bonus_projections') && Schema::hasColumn('finance_payroll_posting_inbox', 'hr_bonus_projection_id')) {
            $q = DB::table('HR_bonus_projections as p')->leftJoin('finance_payroll_posting_inbox as f','f.id','=','p.finance_posting_id')
                ->whereIn('p.status',['submitted','finance_processing','finalized'])->whereNull('f.id');
            $this->fromQuery($issues, 'bonus.missing_finance_bridge', 'critical', 'Bonus projection workflow membutuhkan Finance posting tetapi link tidak ditemukan.', $q, ['p.id','p.status','p.finance_posting_id'], $limit);
        }
    }

    private function schedulerBacklog(array &$issues, int $limit): void
    {
        if (Schema::hasTable('HR_announcements')) {
            $q = DB::table('HR_announcements')->whereNull('deleted_at')->where('status','published')->whereNotNull('ends_at')->where('ends_at','<',now()->subMinutes(30));
            $this->fromQuery($issues, 'scheduler.expired_announcement_backlog', 'warning', 'Announcement melewati ends_at >30 menit tetapi belum expired.', $q, ['id','title','ends_at','status'], $limit);
        }
        if (Schema::hasTable('HR_announcement_attachments') && Schema::hasTable('HR_announcements')) {
            $q = DB::table('HR_announcement_attachments as a')->join('HR_announcements as n','n.id','=','a.announcement_id')->whereNull('a.deleted_at')->whereNull('a.purged_at')
                ->whereNotNull('n.ends_at')->where('n.ends_at','<',now()->subMinutes(30));
            $this->fromQuery($issues, 'scheduler.attachment_purge_backlog', 'warning', 'Attachment announcement expired belum dipurge.', $q, ['a.id','a.announcement_id','a.original_name','n.ends_at'], $limit);
        }
        if (Schema::hasTable('HR_contract_reminders')) {
            $q = DB::table('HR_contract_reminders')->where('status','pending')->whereDate('remind_on','<',now()->toDateString());
            $this->fromQuery($issues, 'scheduler.contract_reminder_backlog', 'warning', 'Reminder kontrak yang sudah jatuh tempo masih pending.', $q, ['id','contract_id','remind_on','label'], $limit);
        }
    }

    private function duplicateNormalized(array &$issues, string $code, string $severity, string $table, string $column, string $message, int $limit): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return;
        $q = DB::table($table)->selectRaw("LOWER(TRIM(`{$column}`)) AS value, COUNT(*) AS total")
            ->whereNotNull($column)->whereRaw("TRIM(`{$column}`) <> ''")->groupByRaw("LOWER(TRIM(`{$column}`))")->havingRaw('COUNT(*) > 1');
        $this->fromGrouped($issues, $code, $severity, $message, $q, $limit);
    }

    private function fromGrouped(array &$issues, string $code, string $severity, string $message, Builder $query, int $limit): void
    {
        $rows = (clone $query)->limit($limit)->get();
        $count = DB::query()->fromSub(clone $query, 'd')->count();
        $issues[] = $this->issue($code, $severity, $count, $message, $rows->map(fn ($r) => (array) $r)->all());
    }

    private function fromQuery(array &$issues, string $code, string $severity, string $message, Builder $query, array $columns, int $limit): void
    {
        $count = (clone $query)->count();
        $rows = $count > 0 ? (clone $query)->limit($limit)->get($columns)->map(fn ($r) => (array) $r)->all() : [];
        $issues[] = $this->issue($code, $severity, $count, $message, $rows);
    }

    private function issue(string $code, string $severity, int $count, string $message, array $samples): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'count' => $count,
            'status' => $count === 0 ? 'pass' : ($severity === 'info' ? 'info' : 'finding'),
            'message' => $message,
            'samples' => $samples,
        ];
    }

    private function guard(array &$issues, callable $callback, string $code): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $issues[] = $this->issue($code, 'critical', 1, 'Reconciliation check gagal dieksekusi: '.$e->getMessage(), []);
        }
    }
}
