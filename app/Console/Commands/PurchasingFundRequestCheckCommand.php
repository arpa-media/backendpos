<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurchasingFundRequestCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-fund-request';

    protected $description = 'Validate Purchasing Iterasi 03 Fund Request tables, routes, permissions, registry, and Access Matrix.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $missingTables = collect([
            'pur_document_sequences',
            'pur_fund_requests',
            'pur_fund_request_items',
            'pur_fund_request_decisions',
            'pur_document_events',
        ])->reject(fn (string $table): bool => Schema::hasTable($table))->values()->all();

        $expectedColumns = [
            'pur_fund_requests' => [
                'request_number', 'request_type', 'chamber_code', 'outlet_id',
                'request_date', 'needed_date', 'status', 'approval_route',
                'source_key', 'lock_version', 'grand_total',
            ],
            'pur_fund_request_items' => [
                'fund_request_id', 'line_no', 'item_name', 'qty',
                'estimated_unit_price', 'tax_mode', 'tax_percent', 'line_total',
            ],
            'pur_document_events' => [
                'root_request_id', 'document_type', 'document_id', 'event_code',
                'event_label', 'actor_user_id', 'occurred_at',
            ],
        ];

        $missingColumns = [];
        foreach ($expectedColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
        }

        $expectedRoutes = [
            'purchasing.fund-requests.catalogs',
            'purchasing.fund-requests.index',
            'purchasing.fund-requests.store',
            'purchasing.fund-requests.show',
            'purchasing.fund-requests.update',
            'purchasing.fund-requests.destroy',
            'purchasing.fund-requests.submit',
            'purchasing.fund-requests.approve',
            'purchasing.fund-requests.reject',
            'purchasing.fund-requests.timeline',
        ];
        $missingRoutes = collect($expectedRoutes)->reject(fn (string $name): bool => Route::has($name))->values()->all();

        $expectedPermissions = [
            'purchasing.fund_request.view',
            'purchasing.fund_request.create',
            'purchasing.fund_request.update',
            'purchasing.fund_request.delete',
            'purchasing.fund_request.submit',
            'purchasing.fund_request.approve_executive',
            'purchasing.fund_request.approve_stock',
            'purchasing.fund_request.reject',
        ];
        $missingPermissions = [];
        if (Schema::hasTable('permissions')) {
            $availablePermissions = DB::table('permissions')->whereIn('name', $expectedPermissions)->pluck('name')->all();
            $missingPermissions = array_values(array_diff($expectedPermissions, $availablePermissions));
        } else {
            $missingPermissions = $expectedPermissions;
        }

        $module = null;
        $registryError = null;
        try {
            $module = $registry->find('fund-requests');
        } catch (Throwable $exception) {
            $registryError = $exception->getMessage();
        }

        $accessMenuExists = Schema::hasTable('access_menus')
            && DB::table('access_menus')
                ->where('code', 'purchasing-fund-requests')
                ->where('path', '/purchasing/fund-requests')
                ->where('is_active', true)
                ->exists();

        $checks = [
            ['Tables', $missingTables === [] ? 'OK' : implode(', ', $missingTables)],
            ['Columns', $missingColumns === [] ? 'OK' : implode(', ', $missingColumns)],
            ['Named routes', $missingRoutes === [] ? 'OK' : implode(', ', $missingRoutes)],
            ['Permissions', $missingPermissions === [] ? 'OK' : implode(', ', $missingPermissions)],
            ['Registry', $registryError ?: (($module['implementation_status'] ?? null) === 'CORE' ? 'CORE' : 'NOT CORE')],
            ['Access Matrix menu', $accessMenuExists ? 'OK' : 'MISSING'],
        ];

        $failed = $missingTables !== []
            || $missingColumns !== []
            || $missingRoutes !== []
            || $missingPermissions !== []
            || $registryError !== null
            || ($module['implementation_status'] ?? null) !== 'CORE'
            || ! $accessMenuExists;

        $this->table(['Check', 'Result'], $checks);
        $this->{$failed ? 'error' : 'info'}('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
