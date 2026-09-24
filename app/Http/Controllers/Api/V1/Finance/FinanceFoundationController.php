<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceFoundationController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $financePortal = null;
        $dashboardMenu = null;

        if (Schema::hasTable('access_portals')) {
            $financePortal = DB::table('access_portals')
                ->where('code', 'finance')
                ->first(['id', 'code', 'name', 'is_active']);
        }

        if (Schema::hasTable('access_menus')) {
            $dashboardMenu = DB::table('access_menus')
                ->where('code', 'finance-dashboard')
                ->first(['id', 'code', 'name', 'path', 'is_active']);
        }

        return ApiResponse::ok([
            'iteration' => '01',
            'foundation_version' => '2026.08.09-finance-iterasi-01',
            'architecture' => [
                'backend_route_modules' => 'routes/finance_modules/*.php',
                'frontend_route_modules' => 'src/modules/finance/route-modules/*.js',
                'frontend_menu_modules' => 'src/modules/finance/menu-modules/*.js',
                'access_matrix_required' => true,
                'posting_model' => 'double-entry',
            ],
            'portal' => $financePortal ? [
                'code' => (string) $financePortal->code,
                'name' => (string) $financePortal->name,
                'is_active' => (bool) $financePortal->is_active,
            ] : null,
            'dashboard_menu' => $dashboardMenu ? [
                'code' => (string) $dashboardMenu->code,
                'name' => (string) $dashboardMenu->name,
                'path' => (string) $dashboardMenu->path,
                'is_active' => (bool) $dashboardMenu->is_active,
            ] : null,
            'stabilization' => [
                'cogs_mariadb_reserved_alias' => 'FIXED',
                'discount_report_all_outlet_month' => 'OPTIMIZED',
                'purchasing_account_receivable' => 'DISABLED',
            ],
        ], 'Finance Iterasi 01 foundation berhasil dimuat.');
    }
}
