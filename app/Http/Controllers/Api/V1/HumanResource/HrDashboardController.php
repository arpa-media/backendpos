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
        $summary['active'] = (clone $base)->whereRaw("LOWER(COALESCE(status, 'active')) = 'active'")->count();
        $summary['inactive'] = (clone $base)->whereRaw("LOWER(COALESCE(status, 'active')) <> 'active'")->count();

        $managementKeywords = ['management', 'headquarter', 'hq', 'office', 'backoffice', 'manajemen'];
        $warehouseKeywords = ['warehouse', 'gudang'];

        $summary['management'] = $this->countByKeywords($base, $managementKeywords);
        $summary['warehouse'] = $this->countByKeywords($base, $warehouseKeywords);

        return array_map('intval', $summary);
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
