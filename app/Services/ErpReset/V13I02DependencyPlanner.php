<?php

namespace App\Services\ErpReset;

use RuntimeException;

/** Pure, conservative child-first planner. Never disables foreign keys. */
final class V13I02DependencyPlanner
{
    /** Only known transactional self-links / one production cycle may be detached. */
    private const DETACH_ALLOWLIST = [
        'wh_stock_units.parent_stock_unit_id' => 'wh_stock_units',
        'wh_stock_units.root_stock_unit_id' => 'wh_stock_units',
        'wh_v4_finance_general_postings.reversal_posting_id' => 'wh_v4_finance_general_postings',
        'wh_v4_finance_general_postings.reversal_of_posting_id' => 'wh_v4_finance_general_postings',
        'wh_ledger_postings.reversal_of_id' => 'wh_ledger_postings',
        'wh_productions.production_request_id' => 'wh_v3_production_material_requests',
    ];

    /** @param array<string, bool> $selectedTableMap
     *  @param array<int,array{child:string,parent:string,columns:array,parent_columns:array,constraint:string}> $fks
     *  @param array<string,array<string,bool>> $nullable
     */
    public function plan(array $selectedTableMap, array $fks, array $nullable): array
    {
        $names = array_keys(array_filter($selectedTableMap));
        sort($names, SORT_STRING);
        if (!$names) throw new RuntimeException('Tidak ada tabel transaksi terpilih.');
        $selected = array_fill_keys($names, true);
        $dependencies = array_fill_keys($names, []);
        $indegree = array_fill_keys($names, 0);
        $detaches = [];
        $blockers = [];

        foreach ($fks as $fk) {
            $child = $fk['child']; $parent = $fk['parent'];
            if (!isset($selected[$child]) || !isset($selected[$parent])) continue;
            $columns = $fk['columns'];
            $key = count($columns) === 1 ? $child.'.'.$columns[0] : '';
            if ($key !== '' && (self::DETACH_ALLOWLIST[$key] ?? null) === $parent) {
                if (($nullable[$child][$columns[0]] ?? false) !== true) {
                    $blockers[] = 'FK_CYCLE_COLUMN_NOT_NULLABLE: '.$key;
                    continue;
                }
                $detaches[$key] = ['table' => $child, 'column' => $columns[0], 'parent' => $parent];
                continue;
            }
            if ($child === $parent) {
                $blockers[] = 'UNSUPPORTED_SELF_FK: '.$child.'.'.implode(',', $columns);
                continue;
            }
            if (!isset($dependencies[$child][$parent])) {
                $dependencies[$child][$parent] = true;
                $indegree[$parent]++;
            }
        }
        $queue = array_keys(array_filter($indegree, static fn($v) => $v === 0));
        sort($queue, SORT_STRING);
        $order = [];
        while ($queue) {
            $name = array_shift($queue);
            $order[] = $name;
            foreach (array_keys($dependencies[$name]) as $parent) {
                $indegree[$parent]--;
                if ($indegree[$parent] === 0) $queue[] = $parent;
            }
            sort($queue, SORT_STRING);
        }
        if (count($order) !== count($names)) {
            $blockers[] = 'UNRESOLVED_FOREIGN_KEY_CYCLE: '.implode(',', array_keys(array_filter($indegree, static fn($v) => $v > 0)));
        }
        ksort($detaches, SORT_STRING);
        return ['order' => $order, 'detaches' => array_values($detaches), 'blockers' => $blockers];
    }
}
