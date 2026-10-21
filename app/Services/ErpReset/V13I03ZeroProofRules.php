<?php

namespace App\Services\ErpReset;

/** Pure and independently testable, fail-closed I03 audit decisions. */
final class V13I03ZeroProofRules
{
    public static function decision(array $expectedTables, array $remaining, array $protectedBefore, array $protectedAfter, array $criticalBalances, array $blockers): array
    {
        $violations = $blockers;
        $missing = array_values(array_diff($expectedTables, array_keys($remaining)));
        foreach ($missing as $table) $violations[] = 'UNSCANNED_RESET_TABLE: '.$table;
        foreach ($expectedTables as $table) {
            if (array_key_exists($table, $remaining) && (int) $remaining[$table] !== 0) {
                $violations[] = 'NONZERO_RESET_ROWS: '.$table.' = '.$remaining[$table];
            }
        }
        foreach ($protectedBefore as $table => $count) {
            if (!array_key_exists($table, $protectedAfter)) {
                $violations[] = 'UNSCANNED_PROTECTED_TABLE: '.$table;
            } elseif ((int) $protectedAfter[$table] !== (int) $count) {
                $violations[] = 'PROTECTED_COUNT_CHANGED: '.$table.' '.$count.' -> '.$protectedAfter[$table];
            }
        }
        foreach ($criticalBalances as $table => $sums) {
            foreach ($sums as $column => $sum) {
                // Store SUM(DECIMAL) as DB-returned decimal string; no float rounding.
                if (!self::isZeroDecimal((string) $sum)) $violations[] = 'NONZERO_BALANCE: '.$table.'.'.$column.' = '.$sum;
            }
        }
        $violations = array_values(array_unique($violations));
        return ['status' => $violations ? 'BLOCKED' : 'PASS_CLONE_ONLY', 'violations' => $violations];
    }

    public static function isZeroDecimal(string $value): bool
    {
        $value = trim($value);
        return preg_match('/^[+-]?0+(?:\.0+)?$/D', $value) === 1;
    }
}
