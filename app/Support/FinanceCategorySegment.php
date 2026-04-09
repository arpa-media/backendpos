<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

final class FinanceCategorySegment
{
    public const BAR = 'bar';
    public const KITCHEN = 'kitchen';

    private const BAR_CATEGORY_NAMES = [
        'ADD - ON BAR',
        'ADD ON - BAR',
        'ADD ON BAR',
        'ADD-ON BAR',
        'BLACK',
        'BLACK SERIES',
        'BOTTLE',
        'CHOCOLATE',
        'COFFEE',
        'JAYA SODA',
        'MANUAL',
        'MILK BASED',
        'MILK BASED COFFEE',
        'MILKSHAKE',
        'MOCKTAIL',
        'MOCKTAILS',
        'ONLINE BEVERAGES',
        'PALMAS',
        'PROMO',
        'RTD',
        'SIGNATURE',
        'TRADITIONAL',
        'TEA SELECTIONS',
        'TEA',
        'SIGNATURE COFFEE MILK',
    ];

    public static function options(): array
    {
        return [
            ['value' => '', 'label' => 'All Category'],
            ['value' => self::BAR, 'label' => 'Bar'],
            ['value' => self::KITCHEN, 'label' => 'Kitchen'],
        ];
    }

    public static function barCategoryNames(): array
    {
        return self::BAR_CATEGORY_NAMES;
    }

    public static function normalize(?string $segment): string
    {
        $value = strtolower(trim((string) $segment));
        return in_array($value, [self::BAR, self::KITCHEN], true) ? $value : '';
    }

    public static function label(?string $segment): string
    {
        return match (self::normalize($segment)) {
            self::BAR => 'Bar',
            self::KITCHEN => 'Kitchen',
            default => 'All Category',
        };
    }

    public static function apply(Builder $query, string $categoryColumn, ?string $segment): void
    {
        $normalized = self::normalize($segment);
        if ($normalized === '') {
            return;
        }

        $sql = self::normalizedSql($categoryColumn);
        $placeholders = implode(', ', array_fill(0, count(self::BAR_CATEGORY_NAMES), '?'));

        if ($normalized === self::BAR) {
            $query->whereRaw("{$sql} IN ({$placeholders})", self::BAR_CATEGORY_NAMES);
            return;
        }

        $query->whereRaw("{$sql} NOT IN ({$placeholders})", self::BAR_CATEGORY_NAMES);
    }

    private static function normalizedSql(string $column): string
    {
        return 'UPPER(TRIM(COALESCE(' . $column . ", '')))";
    }
}
