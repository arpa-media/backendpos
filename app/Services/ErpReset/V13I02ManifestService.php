<?php

namespace App\Services\ErpReset;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * ERP POS V13 I01: read-only, fail-closed reset inventory. NEVER executes DML/DDL.
 * A plan hash is a fingerprint, NOT permission to execute a destructive action.
 */
final class V13I02ManifestService
{
    private const DOMAINS = ['stock', 'warehouse', 'purchasing', 'finance', 'cogs'];
    private const BUSINESS_DATES = [
        'business_date', 'transaction_date', 'journal_date', 'document_date',
        'request_date', 'order_date', 'invoice_date', 'payment_date',
        'production_date', 'opname_date', 'effective_date', 'variance_date',
        'settlement_date', 'receipt_date', 'stock_date', 'transfer_date',
        'date', 'period_from',
    ];
    private const MONEY_COLUMNS = [
        'total_debit', 'total_credit', 'amount', 'total_amount', 'total_cost',
        'inventory_value', 'total_value', 'net_amount', 'opening_balance',
        'balance_due', 'debit', 'credit', 'final_cogs_value', 'net_variance_value',
        'qty_base', 'on_hand_qty', 'quantity',
    ];

    public function build(array $options): array
    {
        $selectedScopes = $this->parseScopes((string) ($options['scope'] ?? 'all'));
        $period = $this->parsePeriod($options);
        $scan = (string) ($options['scan'] ?? 'estimate');
        if (! in_array($scan, ['estimate', 'exact'], true)) {
            throw new InvalidArgumentException('--scan harus estimate atau exact.');
        }
        $details = (bool) ($options['details'] ?? false);
        $progress = $options['progress'] ?? null;
        $maxDetails = (int) ($options['max_details'] ?? 12);
        if ($maxDetails < 1 || $maxDetails > 100) {
            throw new InvalidArgumentException('--max-details harus 1 sampai 100.');
        }

        $baselinePath = base_path('resources/erp-reset/v13_i02_baseline.json');
        $baseline = json_decode((string) File::get($baselinePath), true, 512, JSON_THROW_ON_ERROR);
        $reference = $baseline['tables'] ?? [];
        if (count($reference) !== 489) {
            throw new RuntimeException('Baseline reset V13 I01 tidak valid; diperlukan 489 tabel referensi.');
        }

        // Exactly four read-only metadata statements; NO schema modifications.
        $schemaName = DB::connection()->getDatabaseName();
        if (! preg_match('/^[A-Za-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException('Nama database tidak valid untuk preflight.');
        }
        $metaTables = DB::select('SELECT TABLE_NAME AS table_name, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$schemaName, 'BASE TABLE']);
        $metaCols = DB::select('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$schemaName]);
        $metaFks = DB::select('SELECT TABLE_NAME AS child_table, COLUMN_NAME AS child_column, REFERENCED_TABLE_NAME AS parent_table,
                REFERENCED_COLUMN_NAME AS parent_column, CONSTRAINT_NAME AS constraint_name, ORDINAL_POSITION AS column_order
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$schemaName]);

        $live = [];
        foreach ($metaTables as $t) {
            $live[$t->table_name] = ['estimated_rows' => $t->estimated_rows === null ? null : (int) $t->estimated_rows,
                'data_bytes' => (int) $t->data_bytes, 'index_bytes' => (int) $t->index_bytes,
                'columns' => [], 'foreign_keys' => []];
        }
        foreach ($metaCols as $c) {
            if (isset($live[$c->table_name])) {
                $live[$c->table_name]['columns'][$c->column_name] = strtolower($c->data_type);
            }
        }
        $fkGroups = [];
        foreach ($metaFks as $fk) {
            if (! isset($live[$fk->child_table])) continue;
            $key = $fk->child_table . ':' . $fk->constraint_name;
            if (! isset($fkGroups[$key])) {
                $fkGroups[$key] = ['child' => $fk->child_table, 'parent' => $fk->parent_table,
                    'constraint' => $fk->constraint_name, 'columns' => [], 'parent_columns' => []];
            }
            $fkGroups[$key]['columns'][] = $fk->child_column;
            $fkGroups[$key]['parent_columns'][] = $fk->parent_column;
        }
        foreach ($fkGroups as $fk) {
            $live[$fk['child']]['foreign_keys'][] = $fk;
        }

        $blockers = [];
        $warnings = [];
        $catalog = [];
        $drift = ['new_tables' => [], 'missing_tables' => [], 'new_columns' => [], 'removed_columns' => [], 'changed_foreign_keys' => []];
        foreach (array_keys($reference) as $name) {
            if (! isset($live[$name])) $drift['missing_tables'][] = $name;
        }
        foreach ($live as $table => $value) {
            if (! isset($reference[$table])) $drift['new_tables'][] = $table;
            $baselineEntry = $reference[$table] ?? null;
            $domain = $baselineEntry['domain'] ?? $this->inferDomain($table);
            $classification = $baselineEntry['classification'] ?? 'unclassified_new_table';
            $selected = $classification === 'transaction_or_projection' && in_array($domain, $selectedScopes, true);
            if ($classification === 'unclassified_new_table' && in_array($domain, $selectedScopes, true)) {
                $blockers[] = ['code' => 'UNKNOWN_TABLE', 'table' => $table,
                    'message' => 'Tabel baru pada domain reset tidak ada dalam baseline; klasifikasi manual wajib.'];
            }
            if ($baselineEntry !== null) {
                $oldCols = $baselineEntry['columns'];
                $added = array_values(array_diff(array_keys($value['columns']), $oldCols));
                $removed = array_values(array_diff($oldCols, array_keys($value['columns'])));
                if ($added) $drift['new_columns'][$table] = $added;
                if ($removed) $drift['removed_columns'][$table] = $removed;
                if ($selected && $removed) {
                    $blockers[] = ['code' => 'MISSING_COLUMNS', 'table' => $table, 'columns' => $removed,
                        'message' => 'Kolom baseline transaksi hilang; perlu review migrasi.'];
                }
                $oldFks = array_map(static fn ($fk) => $fk['to_table'] . '|' . str_replace('`', '', $fk['columns']), $baselineEntry['foreign_keys']);
                $newFks = array_map(static fn ($fk) => $fk['parent'] . '|' . implode(',', $fk['columns']), $value['foreign_keys']);
                sort($oldFks); sort($newFks);
                if ($oldFks !== $newFks) {
                    $drift['changed_foreign_keys'][$table] = ['dump' => count($oldFks), 'live' => count($newFks)];
                    if ($selected) {
                        $blockers[] = ['code' => 'FK_SCHEMA_DRIFT', 'table' => $table,
                            'message' => 'Foreign key berbeda dari dump; inspeksi sebelum menentukan urutan reset.'];
                    }
                }
            }

            $datePath = $selected ? $this->resolveDatePath($table, $live, $reference) : null;
            if ($selected && $period['mode'] !== 'full') {
                if ($datePath === null) {
                    $blockers[] = ['code' => 'NO_BUSINESS_DATE_PATH', 'table' => $table,
                        'message' => 'Tidak ada kolom tanggal bisnis atau jalur FK ke tanggal bisnis. Tidak aman untuk partial reset.'];
                } elseif ($datePath['column'] !== 'business_date') {
                    $blockers[] = ['code' => 'DATE_SEMANTICS_REVIEW', 'table' => $table,
                        'message' => 'Tanggal menggunakan '.$datePath['column'].'; harus divalidasi terhadap waktu bisnis canonical.'];
                }
                if ($datePath !== null && in_array($datePath['column'], ['period_from'], true)) {
                    $blockers[] = ['code' => 'PERIOD_OVERLAP_NOT_IMPLEMENTED', 'table' => $table,
                        'message' => 'Range periode memerlukan logika overlap, bukan tanggal tunggal.'];
                }
                if ($datePath === null && $this->isProjection($table)) {
                    $warnings[] = ['code' => 'PROJECTION_REBUILD_REQUIRED', 'table' => $table,
                        'message' => 'Saldo proyeksi tidak dapat dihapus berdasarkan tanggal; I02 wajib reproject.'];
                }
            }
            $catalog[$table] = [
                'domain' => $domain, 'classification' => $classification, 'included' => $selected,
                'estimated_total_rows' => $value['estimated_rows'], 'estimate_is_approximate' => true,
                'data_bytes' => $value['data_bytes'], 'index_bytes' => $value['index_bytes'],
                'outlet_field' => $this->outletField($value['columns']),
                'date_path' => $datePath, 'foreign_key_count' => count($value['foreign_keys']),
                'foreign_key_targets' => array_values(array_unique(array_column($value['foreign_keys'], 'parent'))),
                'actual_total_rows' => null, 'period_rows' => null, 'detail' => [],
            ];
        }

        // If an excluded/protected/other table points TO a table to delete,
        // it cannot be ignored even when the underlying FK uses SET NULL or CASCADE.
        foreach ($fkGroups as $fk) {
            $p = $fk['parent']; $c = $fk['child'];
            if (! ($catalog[$p]['included'] ?? false)) continue;
            if ($catalog[$c]['included'] ?? false) continue;
            $blockers[] = ['code' => 'INBOUND_CROSS_SCOPE_FK', 'table' => $c,
                'references_table' => $p, 'constraint' => $fk['constraint'],
                'message' => 'Tabel di luar scope memiliki FK ke transaksi target; perlu kebijakan detach/relink.'];
        }
        // The hard-coded reference from the dump is a guard even when prod FK is missing.
        foreach ($reference as $c => $row) {
            if (isset($live[$c])) continue;
            if ($row['classification'] === 'transaction_or_projection' && in_array($row['domain'], $selectedScopes, true)) {
                $blockers[] = ['code' => 'MISSING_DUMP_TRANSACTION_TABLE', 'table' => $c,
                    'message' => 'Tabel pada dump tidak ada di server ini; pastikan migration tidak pindah domain.'];
            }
        }
        foreach ($live as $child => $row) {
            foreach ($row['columns'] as $column => $_type) {
                if (! in_array($column, ['reference_id', 'source_id', 'document_id', 'aggregate_id', 'source_document_id'], true)) continue;
                if (! ($catalog[$child]['included'] ?? false)) continue;
                $warnings[] = ['code' => 'POLYMORPHIC_OR_SOFT_REFERENCE', 'table' => $child,
                    'column' => $column, 'message' => 'Referensi non-FK mungkin perlu pelacakan sumber/anak lintas modul.'];
            }
        }
        // Always make it explicit that business sources and schedulers are not part of this dry-run.
        $warnings[] = ['code' => 'AUTO_REPOST_RISK', 'message' => 'POS sales/HR payroll tetap ada; scheduler, outbox dan autopost Finance dapat membuat ulang posting lama. I02 membutuhkan cutover watermark dan freeze.'];
        $warnings[] = ['code' => 'NO_DESTRUCTIVE_EXECUTOR', 'message' => 'I01 hanya manifest. Hash bukan otorisasi --execute. Backup/restore rehearsal, worker pause dan I02/I03 wajib.'];

        // IMPORTANT: count(*) and group previews are opt-in because the live DB may exceed 40 GB.
        $selectedNames = array_keys(array_filter($catalog, static fn ($v) => $v['included']));
        if ($scan === 'exact') {
            foreach ($selectedNames as $i => $name) {
                if (is_callable($progress)) $progress('exact', $i + 1, count($selectedNames), $name);
                $catalog[$name]['actual_total_rows'] = (int) DB::table($name)->count();
                $p = $catalog[$name]['date_path'];
                if ($period['mode'] === 'full') {
                    $catalog[$name]['period_rows'] = $catalog[$name]['actual_total_rows'];
                } elseif ($p !== null && $p['column'] !== 'period_from') {
                    $catalog[$name]['period_rows'] = $this->scopedCount($name, $p, $period);
                }
            }
        }
        $previewed = [];
        if ($details) {
            $priority = ['stk_inventory_movements', 'stk_opening_stocks', 'stk_inventory_balances',
                'wh_ledger_entries', 'wh_ledger_postings', 'wh_batch_balances', 'wh_v7_production_stock_balances',
                'pur_invoices', 'pur_invoice_payments', 'finance_journal_entries', 'finance_journal_entry_lines',
                'finance_treasury_transactions', 'cogs_sale_consumptions', 'cogs_stock_variances'];
            usort($selectedNames, static fn ($a, $b) => (array_search($a, $priority, true) === false ? 1000 : array_search($a, $priority, true))
                <=> (array_search($b, $priority, true) === false ? 1000 : array_search($b, $priority, true)) ?: strcmp($a, $b));
            foreach (array_slice($selectedNames, 0, $maxDetails) as $i => $name) {
                if (is_callable($progress)) $progress('detail', $i + 1, min($maxDetails, count($selectedNames)), $name);
                $p = $catalog[$name]['date_path'];
                if ($period['mode'] !== 'full' && ($p === null || $p['column'] === 'period_from')) continue;
                $catalog[$name]['detail'] = $this->detail($name, $live[$name]['columns'], $p, $period);
                $previewed[] = $name;
            }
        }
        ksort($catalog);
        $status = empty($blockers) ? 'REVIEW_REQUIRED' : 'BLOCKED';
        $canonical = [
            'release' => 'V13-I01', 'database' => $schemaName,
            'scope' => $selectedScopes, 'period' => $period,
            'tables' => array_map(static fn ($t) => [$t['classification'], $t['included'], $t['estimated_total_rows'], $t['date_path']], $catalog),
            'foreign_keys' => $fkGroups, 'blockers' => $blockers, 'scan' => $scan,
        ];
        $schemaFingerprint = hash('sha256', json_encode([$schemaName, array_map(static fn ($v) => [array_keys($v['columns']), $v['foreign_keys']], $live)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $planHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $domainsCount = [];
        foreach (self::DOMAINS as $d) {
            $domainsCount[$d] = count(array_filter($catalog, static fn ($t) => $t['included'] && $t['domain'] === $d));
        }
        return [
            'release' => 'V13-I01', 'read_only' => true, 'execution_supported' => false,
            'generated_at' => now()->toIso8601String(), 'environment' => app()->environment(),
            'database' => $schemaName, 'baseline_dump' => $baseline['dump_date'],
            'timezone_policy' => ['pos_business_date' => 'Asia/Makassar', 'note' => 'Use stored business_date where available; do not use created_at as a substitute.'],
            'scope' => $selectedScopes, 'period' => $period, 'scan' => $scan,
            'plan_id' => 'V13I01-'.strtoupper(substr($planHash, 0, 20)), 'plan_sha256' => $planHash,
            'schema_sha256' => $schemaFingerprint, 'result' => $status,
            'summary' => [
                'dump_tables' => count($reference), 'live_tables' => count($live), 'selected_tables' => count(array_filter($catalog, static fn ($v) => $v['included'])),
                'selected_by_domain' => $domainsCount, 'blockers' => count($blockers), 'warnings' => count($warnings),
                'detail_tables_scanned' => count($previewed), 'counts_are_estimates_unless_scan_exact' => $scan !== 'exact',
                'exact_total_rows' => $scan === 'exact' ? array_sum(array_map(static fn ($v) => $v['included'] ? ($v['actual_total_rows'] ?? 0) : 0, $catalog)) : null,
                'exact_period_rows_scannable' => $scan === 'exact' ? array_sum(array_map(static fn ($v) => $v['included'] ? ($v['period_rows'] ?? 0) : 0, $catalog)) : null,
                'estimated_selected_rows' => array_sum(array_map(static fn ($v) => $v['included'] ? ($v['estimated_total_rows'] ?? 0) : 0, $catalog)),
            ],
            'drift' => $drift, 'blockers' => $blockers, 'warnings' => $warnings, 'tables' => $catalog,
        ];
    }

    private function parseScopes(string $input): array
    {
        $parts = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', explode(',', $input))))));
        if ($parts === ['all']) return self::DOMAINS;
        if (! $parts || in_array('all', $parts, true) || array_diff($parts, self::DOMAINS)) {
            throw new InvalidArgumentException('--scope harus all atau daftar stock,warehouse,purchasing,finance,cogs.');
        }
        sort($parts);
        return $parts;
    }

    private function parsePeriod(array $options): array
    {
        $full = (bool) ($options['full'] ?? false);
        $month = trim((string) ($options['month'] ?? ''));
        $from = trim((string) ($options['from'] ?? ''));
        $to = trim((string) ($options['to'] ?? ''));
        if ((int) $full + (int) ($month !== '') + (int) ($from !== '' || $to !== '') !== 1) {
            throw new InvalidArgumentException('Pilih tepat satu mode: --full, --month=YYYY-MM, atau --from dan --to.');
        }
        if ($full) return ['mode' => 'full', 'from' => null, 'to' => null];
        if ($month !== '') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new InvalidArgumentException('Format --month harus YYYY-MM.');
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
            if (! $start || $start->format('Y-m') !== $month) throw new InvalidArgumentException('Bulan tidak valid.');
            return ['mode' => 'month', 'from' => $start->format('Y-m-d'), 'to' => $start->format('Y-m-t')];
        }
        foreach ([$from, $to] as $day) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (! $date || $date->format('Y-m-d') !== $day) throw new InvalidArgumentException('--from dan --to harus YYYY-MM-DD yang valid.');
        }
        if ($from > $to) throw new InvalidArgumentException('--from tidak boleh sesudah --to.');
        return ['mode' => 'range', 'from' => $from, 'to' => $to];
    }

    private function inferDomain(string $table): string
    {
        foreach (['stk_' => 'stock', 'wh_' => 'warehouse', 'pur_' => 'purchasing', 'finance_' => 'finance', 'cogs_' => 'cogs'] as $prefix => $domain) {
            if (str_starts_with($table, $prefix)) return $domain;
        }
        return 'outside_scope';
    }

    private function isProjection(string $table): bool
    {
        return str_contains($table, 'balance') || str_contains($table, 'snapshot') || str_contains($table, 'stock_units');
    }

    private function outletField(array $columns): ?string
    {
        foreach (['outlet_id', 'warehouse_id'] as $field) if (isset($columns[$field])) return $field;
        return null;
    }

    private function resolveDatePath(string $table, array $live, array $reference): ?array
    {
        // The direct business date beats timestamps and arbitrary creation dates.
        $cols = $live[$table]['columns'];
        foreach (self::BUSINESS_DATES as $date) {
            if (isset($cols[$date]) && in_array($cols[$date], ['date', 'datetime', 'timestamp'], true)) {
                return ['column' => $date, 'owner_table' => $table, 'owner_outlet_field' => $this->outletField($cols), 'joins' => [], 'source' => $date === 'business_date' ? 'canonical' : 'review_field'];
            }
        }
        // Traverse up to two single-column FKs, never use created_at in lieu of business date.
        $queue = [[$table, []]];
        $seen = [$table => true];
        while ($queue) {
            [$current, $path] = array_shift($queue);
            if (count($path) >= 2) continue;
            foreach ($live[$current]['foreign_keys'] as $fk) {
                $parent = $fk['parent'];
                if (count($fk['columns']) !== 1 || ! isset($live[$parent]) || isset($seen[$parent])) continue;
                if (($reference[$parent]['classification'] ?? '') !== 'transaction_or_projection') continue;
                $chain = array_merge($path, [[
                    'child' => $current, 'parent' => $parent,
                    'child_column' => $fk['columns'][0], 'parent_column' => $fk['parent_columns'][0],
                ]]);
                foreach (self::BUSINESS_DATES as $date) {
                    if (isset($live[$parent]['columns'][$date]) && in_array($live[$parent]['columns'][$date], ['date','datetime','timestamp'], true)) {
                        return ['column' => $date, 'owner_table' => $parent, 'joins' => $chain,
                            'owner_outlet_field' => $this->outletField($live[$parent]['columns']),
                            'source' => $date === 'business_date' ? 'fk_canonical' : 'fk_review_field'];
                    }
                }
                $seen[$parent] = true; $queue[] = [$parent, $chain];
            }
        }
        return null;
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) throw new RuntimeException('Invalid SQL metadata identifier.');
        return '`'.$identifier.'`';
    }

    private function sqlContext(string $table, ?array $path, array $period): array
    {
        $sql = $this->quote($table).' t';
        $bindings = [];
        $alias = 't';
        if ($path !== null) {
            foreach ($path['joins'] as $i => $join) {
                $next = 'p'.$i;
                $sql .= ' INNER JOIN '.$this->quote($join['parent']).' '.$next.' ON '.$alias.'.'.$this->quote($join['child_column']).' = '.$next.'.'.$this->quote($join['parent_column']);
                $alias = $next;
            }
        }
        $where = '1 = 1';
        if ($period['mode'] !== 'full' && $path !== null) {
            $col = $alias.'.'.$this->quote($path['column']);
            $where .= ' AND '.$col.' >= ? AND '.$col.' < ?';
            $bindings = [$period['from'], (new \DateTimeImmutable($period['to']))->modify('+1 day')->format('Y-m-d')];
        }
        return [$sql, $where, $bindings, $alias];
    }

    private function scopedCount(string $table, array $path, array $period): int
    {
        [$from, $where, $bindings] = $this->sqlContext($table, $path, $period);
        return (int) (DB::selectOne('SELECT COUNT(*) AS n FROM '.$from.' WHERE '.$where, $bindings)->n ?? 0);
    }

    private function detail(string $table, array $cols, ?array $path, array $period): array
    {
        [$from, $where, $bindings, $dateAlias] = $this->sqlContext($table, $path, $period);
        $outlet = $this->outletField($cols);
        $outletSql = $outlet !== null ? 't.'.$this->quote($outlet)
            : (($path !== null && $path['owner_outlet_field'] !== null) ? $dateAlias.'.'.$this->quote($path['owner_outlet_field']) : 'NULL');
        $dateSql = $path !== null ? 'DATE_FORMAT('.$dateAlias.'.'.$this->quote($path['column']).', \'%Y-%m\')' : 'NULL';
        $measures = [];
        foreach (self::MONEY_COLUMNS as $name) {
            if (isset($cols[$name]) && in_array($cols[$name], ['decimal','double','float','integer','bigint','int'], true)) {
                $measures[] = $name;
            }
            if (count($measures) >= 3) break;
        }
        $sql = 'SELECT '.$outletSql.' AS outlet_or_warehouse_id, '.$dateSql.' AS month_key, COUNT(*) AS rows_count';
        foreach ($measures as $measure) {
            $sql .= ', COALESCE(SUM(t.'.$this->quote($measure).'), 0) AS '.$this->quote('sum_'.$measure);
        }
        $sql .= ' FROM '.$from.' WHERE '.$where.' GROUP BY outlet_or_warehouse_id, month_key ORDER BY rows_count DESC LIMIT 100';
        $rows = DB::select($sql, $bindings);
        return ['aggregation' => 'exact_in_scanned_table', 'truncated_at' => 100, 'measure_columns' => $measures,
            'rows' => json_decode(json_encode($rows, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)];
    }
}
