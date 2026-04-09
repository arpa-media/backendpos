<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class OutletBusinessDateScope
{
    public static function groupOutletIdsByTimezone(array $outletIds, ?string $fallbackTimezone = null): array
    {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, config('app.timezone', 'Asia/Jakarta'));
        $ids = array_values(array_unique(array_filter(array_map('strval', $outletIds))));

        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('outlets')
            ->whereIn('id', $ids)
            ->get(['id', 'timezone']);

        $groups = [];
        $knownIds = [];
        foreach ($rows as $row) {
            $id = (string) ($row->id ?? '');
            if ($id === '') {
                continue;
            }
            $knownIds[$id] = true;
            $tz = TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), $fallback);
            $groups[$tz] ??= [];
            $groups[$tz][] = $id;
        }

        foreach ($ids as $id) {
            if (!isset($knownIds[$id])) {
                $groups[$fallback] ??= [];
                $groups[$fallback][] = $id;
            }
        }

        foreach ($groups as $tz => $groupIds) {
            $groups[$tz] = array_values(array_unique($groupIds));
        }

        return $groups;
    }

    public static function applyExactScope(
        object $query,
        string $outletIdColumn,
        array $outletIds,
        string $createdAtColumn,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $fallbackTimezone = null,
        ?string $saleNumberColumn = null
    ): array {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, config('app.timezone', 'Asia/Jakarta'));
        $groups = self::groupOutletIdsByTimezone($outletIds, $fallback);

        if (empty($groups)) {
            return TransactionDate::applyExactBusinessDateScope(
                $query,
                $createdAtColumn,
                $dateFrom,
                $dateTo,
                $fallback,
                $saleNumberColumn
            );
        }

        $query->where(function ($outer) use ($groups, $outletIdColumn, $createdAtColumn, $dateFrom, $dateTo, $saleNumberColumn) {
            $first = true;
            foreach ($groups as $timezone => $ids) {
                $method = $first ? 'where' : 'orWhere';
                $first = false;
                $outer->{$method}(function ($inner) use ($ids, $timezone, $outletIdColumn, $createdAtColumn, $dateFrom, $dateTo, $saleNumberColumn) {
                    if (count($ids) === 1) {
                        $inner->where($outletIdColumn, '=', $ids[0]);
                    } else {
                        $inner->whereIn($outletIdColumn, $ids);
                    }

                    TransactionDate::applyExactBusinessDateScope(
                        $inner,
                        $createdAtColumn,
                        $dateFrom,
                        $dateTo,
                        $timezone,
                        $saleNumberColumn
                    );
                });
            }
        });

        return TransactionDate::businessDateWindow($dateFrom, $dateTo, $fallback);
    }
}
