<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('finance_companies')) {
            DB::table('finance_companies')->updateOrInsert(
                ['code' => 'APB'],
                [
                    'name' => 'PT APB',
                    'legal_name' => DB::table('finance_companies')->where('code', 'APB')->value('legal_name'),
                    'is_active' => true,
                    'created_at' => DB::table('finance_companies')->where('code', 'APB')->value('created_at') ?: $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->addScopeColumns('pur_fund_requests', 'pur_fr_scope_company_i04');
        $this->addScopeColumns('pur_purchase_orders', 'pur_po_scope_company_i04');
        $this->addScopeColumns('pur_service_orders', 'pur_so_scope_company_i04');
        $this->addScopeColumns('pur_reimburse_orders', 'pur_ro_scope_company_i04');

        if (Schema::hasTable('pur_fund_requests')) {
            DB::table('pur_fund_requests')->whereNull('scope_type')->update([
                'scope_type' => DB::raw("CASE WHEN outlet_id IS NULL THEN 'COMPANY' ELSE 'OUTLET' END"),
                'marking' => 'UNMARKING',
            ]);
            DB::table('pur_fund_requests')->whereNull('marking')->update(['marking' => 'UNMARKING']);

            if (Schema::hasTable('finance_outlet_company_mappings')) {
                DB::statement(<<<'SQL'
UPDATE pur_fund_requests fr
JOIN finance_outlet_company_mappings map
  ON map.outlet_id = fr.outlet_id
 AND map.is_active = 1
 AND (map.effective_from IS NULL OR DATE(map.effective_from) <= DATE(fr.request_date))
 AND (map.effective_to IS NULL OR DATE(map.effective_to) >= DATE(fr.request_date))
SET fr.company_code = UPPER(map.company_code)
WHERE fr.outlet_id IS NOT NULL AND (fr.company_code IS NULL OR fr.company_code = '')
SQL);
            }
        }

        foreach (['pur_purchase_orders', 'pur_service_orders', 'pur_reimburse_orders'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasTable('pur_fund_requests')) {
                continue;
            }
            DB::statement("UPDATE {$table} o JOIN pur_fund_requests fr ON fr.id = o.fund_request_id SET o.scope_type = COALESCE(o.scope_type, fr.scope_type), o.company_code = COALESCE(o.company_code, fr.company_code), o.marking = COALESCE(o.marking, fr.marking, 'UNMARKING')");
            DB::table($table)->whereNull('marking')->update(['marking' => 'UNMARKING']);
        }
    }

    public function down(): void
    {
        $this->dropScopeColumns('pur_reimburse_orders', 'pur_ro_scope_company_i04');
        $this->dropScopeColumns('pur_service_orders', 'pur_so_scope_company_i04');
        $this->dropScopeColumns('pur_purchase_orders', 'pur_po_scope_company_i04');
        $this->dropScopeColumns('pur_fund_requests', 'pur_fr_scope_company_i04');

        // APB is an authoritative Finance master row and may be referenced by
        // later documents. Do not delete it automatically on rollback.
    }

    private function addScopeColumns(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $addScope = ! Schema::hasColumn($table, 'scope_type');
        $addCompany = ! Schema::hasColumn($table, 'company_code');
        $addMarking = ! Schema::hasColumn($table, 'marking');

        if ($addScope || $addCompany || $addMarking) {
            Schema::table($table, function (Blueprint $blueprint) use ($addScope, $addCompany, $addMarking): void {
                if ($addScope) {
                    $blueprint->string('scope_type', 16)->nullable()->after('chamber_code');
                }
                if ($addCompany) {
                    $blueprint->string('company_code', 16)->nullable()->after('scope_type');
                }
                if ($addMarking) {
                    $blueprint->string('marking', 16)->nullable()->after('company_code');
                }
            });
        }

        // Migration only runs once on the target environment; use a deliberately
        // short explicit name to stay under MySQL's 64-character identifier cap.
        if (Schema::hasColumn($table, 'scope_type')
            && Schema::hasColumn($table, 'company_code')
            && ! $this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->index(['scope_type', 'company_code'], $indexName);
            });
        }
    }

    private function dropScopeColumns(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = collect(['scope_type', 'company_code', 'marking'])
            ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values()->all();

        if ($columns === []) {
            return;
        }

        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->dropColumn($columns);
        });
    }
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

};
