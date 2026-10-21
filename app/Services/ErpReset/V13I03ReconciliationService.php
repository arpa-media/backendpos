<?php

namespace App\Services\ErpReset;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * V13 I03: audit-only verification of a V13 I02 FULL + ALL run on an ISOLATED CLONE.
 * Does not modify DB, perform replay, re-materialize or unlock production reset.
 * Requires signed pre-reset snapshot created after plan preparation and before execution.
 */
final class V13I03ReconciliationService
{
    private const DOMAINS = ['stock', 'warehouse', 'purchasing', 'finance', 'cogs'];
    private const CRITICAL = [
        'stk_inventory_balances' => ['on_hand_qty', 'inventory_value'],
        'stk_inventory_movements' => ['quantity', 'total_cost'],
        'stk_opening_stocks' => ['opening_qty', 'inventory_value'],
        'wh_batch_balances' => ['on_hand_qty', 'reserved_qty', 'quarantine_qty', 'inventory_value'],
        'wh_ledger_entries' => ['signed_quantity_base', 'total_cost'],
        'wh_v7_production_stock_balances' => ['qty_base', 'inventory_value'],
        'finance_journal_entries' => ['total_debit', 'total_credit'],
        'finance_journal_entry_lines' => ['debit', 'credit'],
        'finance_treasury_transactions' => ['amount'],
        'wh_v4_treasury_transactions' => ['amount'],
        'pur_invoices' => ['total_amount', 'balance_due'],
        'pur_invoice_payments' => ['amount'],
        'pur_reimburse_payables' => ['balance_due'],
        'wh_supplier_invoices' => ['grand_total', 'balance_due'],
        'wh_sales_invoices' => ['grand_total', 'balance_due'],
        'cogs_sale_consumptions' => ['total_cost', 'total_base_quantity'],
        'cogs_stock_variances' => ['net_variance_value', 'variance_qty_total'],
    ];

    public function __construct(private readonly V13I02ManifestService $manifest) {}

    /** Same MySQL named lock used by I02. Prevents concurrent I02 commands on the clone.
     * Does NOT stop arbitrary workers or POS API writes: operator must freeze clone writers.
     */
    public function snapshot(string $planId, ?callable $progress = null): array
    {
        return $this->withI02Lock(fn () => $this->snapshotLocked($planId, $progress));
    }

    public function verify(string $planId, string $snapshotId, ?callable $progress = null): array
    {
        return $this->withI02Lock(fn () => $this->verifyLocked($planId, $snapshotId, $progress));
    }

    private function withI02Lock(callable $work): array
    {
        $this->assertClone();
        $name = 'v13_i02_'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 40);
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('Concurrent I02/I03 run detected. Stop; do not snapshot/verify during deletion.');
        }
        try {
            return $work();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }

    private function snapshotLocked(string $planId, ?callable $progress = null): array
    {
        $this->assertClone();
        $plan = $this->loadI02Plan($planId);
        $this->assertPlan($plan);
        $run = $this->i02RunPath($planId);
        if (File::exists($run)) throw new RuntimeException('I02 run already exists. Cannot take pre-reset snapshot after execution has started.');
        $catalog = $this->catalog();
        $this->assertSameSchema($plan);
        $this->assertCatalogMatchesPlan($plan, $catalog);
        $target = array_fill_keys($plan['selected_tables'], true);
        $protected = [];
        $selected = [];
        $tables = array_keys($catalog['tables']);
        foreach ($tables as $index => $table) {
            $count = (int) DB::table($table)->count();
            if (isset($target[$table])) $selected[$table] = $count;
            else $protected[$table] = $count;
            if ($progress && (($index + 1) % 20 === 0 || $index === count($tables) - 1)) $progress($index + 1, count($tables), $table);
        }
        if (File::exists($run)) throw new RuntimeException('I02 run started while I03 baseline was collecting. Discard baseline.');
        $id = 'V13I03-'.strtoupper(bin2hex(random_bytes(10)));
        $data = [
            'release' => 'V13-I03', 'kind' => 'PRE_RESET_SNAPSHOT', 'snapshot_id' => $id,
            'plan_id' => $planId, 'created_at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(), 'environment' => app()->environment(),
            'schema_sha256' => $plan['manifest_schema_sha256'],
            'selected_before' => $selected, 'protected_before' => $protected,
            'proof_limits' => [
                'Protected counts are a detection aid, NOT proof of row-content immutability.',
                'Writes must be stopped BEFORE snapshot through final verification.',
                'Source POS sales/HR are outside reset and could trigger automatic repost if writers restart.',
            ],
        ];
        $this->saveSigned($this->path('snapshots', $id), $data, true);
        return ['snapshot_id'=>$id, 'plan_id'=>$planId, 'tables'=>count($tables),
            'selected_tables'=>count($selected), 'protected_tables'=>count($protected),
            'selected_rows'=>array_sum($selected), 'file'=>$this->path('snapshots', $id)];
    }

    private function verifyLocked(string $planId, string $snapshotId, ?callable $progress = null): array
    {
        $this->assertClone();
        $plan = $this->loadI02Plan($planId);
        $this->assertPlan($plan);
        $state = $this->loadSigned($this->i02RunPath($planId));
        $snapshot = $this->loadSigned($this->path('snapshots', $snapshotId));
        $blockers = [];
        if (($state['phase'] ?? null) !== 'complete_awaiting_i03') {
            $blockers[] = 'I02_NOT_COMPLETED: '.($state['phase'] ?? 'unknown');
        }
        if (($state['plan_id'] ?? '') !== $planId || ($snapshot['plan_id'] ?? '') !== $planId) {
            $blockers[] = 'PLAN_ID_MISMATCH';
        }
        if (($snapshot['database'] ?? '') !== DB::connection()->getDatabaseName() ||
            ($snapshot['environment'] ?? '') !== app()->environment() ||
            ($snapshot['schema_sha256'] ?? '') !== $plan['manifest_schema_sha256']) {
            $blockers[] = 'SNAPSHOT_CONTEXT_MISMATCH';
        }
        $start = strtotime((string) ($state['started_at'] ?? ''));
        $taken = strtotime((string) ($snapshot['created_at'] ?? ''));
        if (!$start || !$taken || $taken > $start) $blockers[] = 'SNAPSHOT_NOT_BEFORE_EXECUTION';
        if (($snapshot['selected_before'] ?? []) !== ($state['before_counts'] ?? [])) {
            // Comparing associative maps by key/value (order may differ).
            $a = $snapshot['selected_before'] ?? []; $b = $state['before_counts'] ?? [];
            ksort($a); ksort($b);
            if ($a !== $b) $blockers[] = 'I02_BEFORE_COUNTS_DIFFER_FROM_I03_SNAPSHOT';
        }
        $this->assertSameSchema($plan);
        $catalog = $this->catalog();
        $this->assertCatalogMatchesPlan($plan, $catalog);
        $target = array_fill_keys($plan['selected_tables'], true);
        $protectedAfter = [];
        $remaining = [];
        $critical = [];
        $tables = array_keys($catalog['tables']);
        foreach ($tables as $index => $table) {
            $count = (int) DB::table($table)->count();
            if (isset($target[$table])) $remaining[$table] = $count;
            else $protectedAfter[$table] = $count;
            if ($progress && (($index + 1) % 20 === 0 || $index === count($tables) - 1)) $progress($index + 1, count($tables), $table);
        }
        foreach (self::CRITICAL as $table => $cols) {
            if (!isset($catalog['tables'][$table]) || !isset($target[$table])) {
                $blockers[] = 'CRITICAL_TABLE_NOT_IN_RESET_SCOPE: '.$table;
                continue;
            }
            $available = array_fill_keys($catalog['tables'][$table]['columns'], true);
            $missing = array_diff($cols, array_keys($available));
            if ($missing) {
                $blockers[] = 'CRITICAL_COLUMN_MISSING: '.$table.' '.implode(',', $missing);
                continue;
            }
            if ($remaining[$table] === 0) {
                $critical[$table] = array_fill_keys($cols, '0');
            } else {
                // A single SQL aggregation on each nonempty financial/stock balance table.
                $sums = [];
                foreach ($cols as $col) $sums[] = 'COALESCE(SUM(`'.$col.'`),0) AS `'.$col.'`';
                $row = DB::table($table)->selectRaw(implode(', ', $sums))->first();
                $critical[$table] = [];
                foreach ($cols as $col) $critical[$table][$col] = (string) ($row->$col ?? '0');
            }
        }
        // Cross-scope live foreign keys are an important corruption/orphan guard.
        foreach ($catalog['foreign_keys'] as $fk) {
            if (!isset($target[$fk['parent']]) || isset($target[$fk['child']])) continue;
            if (count($fk['columns']) !== 1) {
                $blockers[] = 'UNVERIFIABLE_EXTERNAL_COMPOSITE_REFERENCE: '.$fk['child'].' -> '.$fk['parent'];
                continue;
            }
            if (DB::table($fk['child'])->whereNotNull($fk['columns'][0])->exists()) {
                $blockers[] = 'EXTERNAL_FK_LINK_REMAINS: '.$fk['child'].'.'.$fk['columns'][0].' -> '.$fk['parent'];
            }
        }
        if (count($protectedAfter) !== count($snapshot['protected_before'] ?? [])) $blockers[] = 'PROTECTED_TABLE_SET_CHANGED';
        if (count($remaining) !== count($plan['selected_tables'])) $blockers[] = 'SELECTED_TABLE_SET_CHANGED';
        $decision = V13I03ZeroProofRules::decision(
            $plan['selected_tables'], $remaining, $snapshot['protected_before'] ?? [], $protectedAfter, $critical, $blockers
        );
        $reportId = 'V13I03-'.strtoupper(bin2hex(random_bytes(10)));
        $report = [
            'release'=>'V13-I03', 'kind'=>'POST_RESET_RECONCILIATION', 'report_id'=>$reportId,
            'generated_at'=>now()->toIso8601String(), 'status'=>$decision['status'],
            'database'=>DB::connection()->getDatabaseName(), 'environment'=>app()->environment(),
            'plan_id'=>$planId, 'snapshot_id'=>$snapshotId,
            'checked_tables'=>count($tables), 'reset_tables'=>count($remaining),
            'protected_tables'=>count($protectedAfter), 'remaining_reset_rows'=>array_sum($remaining),
            'nonzero_reset_tables'=>array_filter($remaining, static fn($n) => $n !== 0),
            'changed_protected_count_tables'=>array_filter($protectedAfter, static fn($n,$table) =>
                !array_key_exists($table, $snapshot['protected_before'] ?? []) || $n !== $snapshot['protected_before'][$table], ARRAY_FILTER_USE_BOTH),
            'balance_sums'=>$critical, 'violations'=>$decision['violations'],
            'not_proven'=>[
                'No reconciliation proof on production: this report is valid only for a frozen isolated clone.',
                'Protected table row counts are verified; their individual row contents are NOT compared.',
                'References not enforced by MySQL foreign keys (polymorphic/id strings) are NOT fully validated.',
                'Historical period reset, automatic replay of finance/stock projections and restart of posting workers are NOT authorized.',
                'A passed report does not grant permission to run I02 against production.',
            ],
        ];
        $this->saveSigned($this->path('reports', $reportId), $report, true);
        return ['status'=>$report['status'], 'report_id'=>$reportId, 'report_file'=>$this->path('reports', $reportId),
            'checked_tables'=>$report['checked_tables'], 'remaining_reset_rows'=>$report['remaining_reset_rows'],
            'nonzero_reset_tables'=>$report['nonzero_reset_tables'], 'changed_protected_count_tables'=>$report['changed_protected_count_tables'],
            'violations'=>$report['violations']];
    }

    public function inspect(): array
    {
        // Lightweight metadata-only inspection; suitable for live database, no proof or DML.
        $report = $this->manifest->build(['scope'=>'all', 'full'=>true, 'scan'=>'estimate']);
        $selected = array_filter($report['tables'], static fn ($t) => $t['included']);
        return ['database'=>$report['database'], 'environment'=>app()->environment(),
            'selected_tables'=>count($selected), 'schema_sha256'=>$report['schema_sha256'],
            'estimated_reset_rows'=>$report['summary']['estimated_selected_rows'],
            'blockers'=>$report['blockers'], 'drift'=>$report['drift'],
            'note'=>'ESTIMATE_ONLY. Not an execution permission, exact zero proof, or period replay.'];
    }

    private function catalog(): array
    {
        $schema = DB::connection()->getDatabaseName();
        $rows = DB::select('SELECT TABLE_NAME AS tbl FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$schema, 'BASE TABLE']);
        $tables = [];
        foreach ($rows as $r) $tables[$r->tbl] = ['columns'=>[]];
        $cols = DB::select('SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$schema]);
        foreach ($cols as $r) if (isset($tables[$r->tbl])) $tables[$r->tbl]['columns'][] = $r->col;
        $fkRows = DB::select('SELECT TABLE_NAME AS child_table, REFERENCED_TABLE_NAME AS parent_table, COLUMN_NAME AS child_column, CONSTRAINT_NAME AS constraint_name, ORDINAL_POSITION AS ord FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$schema]);
        $fks = [];
        foreach ($fkRows as $r) {
            $key = $r->child_table.'|'.$r->constraint_name;
            if (!isset($fks[$key])) $fks[$key] = ['child'=>$r->child_table,'parent'=>$r->parent_table,'columns'=>[]];
            $fks[$key]['columns'][] = $r->child_column;
        }
        return ['tables'=>$tables, 'foreign_keys'=>array_values($fks)];
    }

    private function assertCatalogMatchesPlan(array $plan, array $catalog): void
    {
        foreach ($plan['selected_tables'] as $table) {
            if (!isset($catalog['tables'][$table])) throw new RuntimeException('RESET_TARGET_MISSING: '.$table);
        }
        if (count($plan['selected_tables']) !== 202 || count(array_unique($plan['selected_tables'])) !== 202) {
            throw new RuntimeException('Unexpected selected reset table manifest; stop.');
        }
    }

    private function assertSameSchema(array $plan): void
    {
        $report = $this->manifest->build(['scope'=>'all','full'=>true,'scan'=>'estimate']);
        if (!hash_equals((string) $plan['manifest_schema_sha256'], (string) $report['schema_sha256'])) {
            throw new RuntimeException('I02 plan and live schema hash differ. Do NOT certify.');
        }
        if ($report['blockers']) {
            $codes = array_values(array_unique(array_column($report['blockers'], 'code')));
            // Cross-scope FK must be evaluated using live data; all other drift blocks are fatal.
            $unexpected = array_diff($codes, ['INBOUND_CROSS_SCOPE_FK']);
            if ($unexpected) throw new RuntimeException('Unreviewed manifest blocker(s): '.implode(', ', $unexpected));
        }
    }

    private function assertPlan(array $plan): void
    {
        if (($plan['purpose'] ?? '') !== 'ISOLATED_CLONE_FULL_RESET_ONLY' || ($plan['period']['mode'] ?? '') !== 'full' ||
            ($plan['scope'] ?? null) !== self::DOMAINS || !empty($plan['blockers']) ||
            ($plan['database'] ?? '') !== DB::connection()->getDatabaseName() ||
            ($plan['environment'] ?? '') !== app()->environment()) {
            throw new RuntimeException('I02 plan must be full/all, ready and on the same isolated clone/environment.');
        }
    }

    private function assertClone(): void
    {
        $db = strtolower((string) DB::connection()->getDatabaseName());
        if (DB::connection()->getDriverName() !== 'mysql' ||
            !in_array(strtolower(app()->environment()), ['local','testing'], true) ||
            !preg_match('/(^|_)(clone|test|testing|sandbox|resetlab)(_|$)/', $db)) {
            throw new RuntimeException('I03 verification/snapshot is clone-only (APP_ENV local/testing, DB name *_clone/*_test/*_sandbox/*_resetlab).');
        }
    }

    private function i02RunPath(string $id): string
    {
        $this->assertI02Id($id);
        return storage_path('app/erp-reset/v13-i02/runs/'.$id.'.json');
    }

    private function loadI02Plan(string $id): array
    {
        $this->assertI02Id($id);
        $plan = $this->loadSigned(storage_path('app/erp-reset/v13-i02/plans/'.$id.'.json'));
        if (($plan['plan_id'] ?? '') !== $id) throw new RuntimeException('Plan ID in signed plan mismatched.');
        return $plan;
    }

    private function assertI02Id(string $id): void
    {
        if (!preg_match('/^V13I02-[A-F0-9]{20}$/D', $id)) throw new RuntimeException('I02 plan ID invalid.');
    }

    private function path(string $bucket, string $id): string
    {
        if (!in_array($bucket, ['snapshots','reports'], true) || !preg_match('/^V13I03-[A-F0-9]{20}$/D', $id)) {
            throw new RuntimeException('I03 snapshot/report ID invalid.');
        }
        return storage_path('app/erp-reset/v13-i03/'.$bucket.'/'.$id.'.json');
    }

    private function loadSigned(string $path): array
    {
        if (!File::isFile($path)) throw new RuntimeException('Signed file absent: '.basename($path));
        $json = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $saved = $json['hmac'] ?? null;
        if (!is_string($saved)) throw new RuntimeException('HMAC missing.');
        unset($json['hmac']);
        $expected = $this->hmac($json);
        if (!hash_equals($expected, $saved)) throw new RuntimeException('I02/I03 file HMAC mismatch.');
        return $json;
    }

    private function saveSigned(string $path, array $data, bool $immutable): void
    {
        File::ensureDirectoryExists(dirname($path), 0700, true);
        if ($immutable && File::exists($path)) throw new RuntimeException('Immutable report exists.');
        $data['hmac'] = $this->hmac($data);
        $tmp = $path.'.tmp.'.getmypid();
        $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $out, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot safely persist reconciliation audit file.');
        }
        @chmod($path, 0600);
    }

    private function hmac(array $data): string
    {
        if (!config('app.key')) throw new RuntimeException('Missing APP_KEY for verification signing.');
        return hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
