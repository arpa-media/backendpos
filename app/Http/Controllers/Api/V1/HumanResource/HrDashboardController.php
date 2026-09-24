<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrDashboardController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([
            'outlets' => $this->outletSummary(),
            'squads' => $this->squadSummary(),
        ], 'OK');
    }

    private function outletSummary(): array
    {
        $summary = ['outlet' => 0, 'warehouse' => 0, 'headquarter' => 0, 'total' => 0];
        if (!Schema::hasTable('outlets')) return $summary;

        $rows = DB::table('outlets')
            ->selectRaw("LOWER(COALESCE(type, 'outlet')) as type, COUNT(*) as total")
            ->when(Schema::hasColumn('outlets', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->groupBy(DB::raw("LOWER(COALESCE(type, 'outlet'))"))
            ->get();

        foreach ($rows as $row) {
            $type = $this->normalizeOutletType($row->type);
            $summary[$type] = ($summary[$type] ?? 0) + (int) $row->total;
        }

        $summary['total'] = (int) ($summary['outlet'] + $summary['warehouse'] + $summary['headquarter']);
        return $summary;
    }

    private function squadSummary(): array
    {
        $summary = ['active' => 0, 'inactive' => 0, 'management' => 0, 'warehouse' => 0];
        if (!Schema::hasTable('HR_squads')) return $summary;

        $base = DB::table('HR_squads')->whereNull('deleted_at');
        $operational = clone $base;
        $this->applyOperationalSquadScope($operational);

        $summary['active'] = (clone $operational)->whereRaw("LOWER(COALESCE(status, 'active')) = 'active'")->count();
        $summary['inactive'] = (clone $operational)->whereRaw("LOWER(COALESCE(status, 'active')) <> 'active'")->count();

        $managementKeywords = ['management', 'headquarter', 'hq', 'office', 'backoffice', 'manajemen'];
        $warehouseKeywords = ['warehouse', 'gudang'];

        $summary['management'] = $this->countByKeywords($operational, $managementKeywords);
        $summary['warehouse'] = $this->countByKeywords($operational, $warehouseKeywords);

        return array_map('intval', $summary);
    }

    private function applyOperationalSquadScope($query): void
    {
        foreach (['role_name', 'access_role'] as $roleColumn) {
            if (! Schema::hasColumn('HR_squads', $roleColumn)) continue;
            $query->where(function ($roleScope) use ($roleColumn) {
                $roleScope->whereNull($roleColumn)
                    ->orWhereRaw("UPPER(TRIM(COALESCE(`{$roleColumn}`, ''))) NOT IN ('STAKEHOLDER', 'OBSERVER')");
            });
        }

        if (
            Schema::hasColumn('HR_squads', 'user_id')
            && Schema::hasTable('user_access_assignments')
            && Schema::hasTable('access_roles')
        ) {
            $query->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('user_access_assignments as hr_i09_dash_uaa')
                    ->join('access_roles as hr_i09_dash_ar', 'hr_i09_dash_ar.id', '=', 'hr_i09_dash_uaa.access_role_id')
                    ->whereColumn('hr_i09_dash_uaa.user_id', 'HR_squads.user_id')
                    ->whereIn('hr_i09_dash_ar.code', ['STAKEHOLDER', 'OBSERVER']);
            });
        }
    }

    private function countByKeywords($base, array $keywords): int
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('HR_squads', 'assignment') ? 'assignment' : null,
            Schema::hasColumn('HR_squads', 'chamber_name') ? 'chamber_name' : null,
            Schema::hasColumn('HR_squads', 'division_name') ? 'division_name' : null,
            Schema::hasColumn('HR_squads', 'position_name') ? 'position_name' : null,
            Schema::hasColumn('HR_squads', 'role_name') ? 'role_name' : null,
        ]));

        if (empty($columns)) return 0;

        return (clone $base)->where(function ($query) use ($columns, $keywords) {
            foreach ($columns as $column) {
                foreach ($keywords as $keyword) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", ['%' . strtolower($keyword) . '%']);
                }
            }
        })->count();
    }

    private function normalizeOutletType($type): string
    {
        $type = strtolower(trim((string) $type));
        if (in_array($type, ['warehouse', 'gudang'], true)) return 'warehouse';
        if (in_array($type, ['headquarter', 'hq', 'office', 'backoffice'], true)) return 'headquarter';
        return 'outlet';
    }
}
