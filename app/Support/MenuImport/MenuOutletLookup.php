<?php

namespace App\Support\MenuImport;

use App\Models\Outlet;
use Illuminate\Support\Str;

class MenuOutletLookup
{
    /**
     * @param iterable<int, Outlet> $outlets
     * @return array<string, Outlet>
     */
    public static function build(iterable $outlets): array
    {
        $lookup = [];

        foreach ($outlets as $outlet) {
            foreach (self::aliasesForOutlet($outlet) as $key) {
                $lookup[$key] = $outlet;
            }
        }

        return $lookup;
    }

    /**
     * @return array<int, string>
     */
    public static function aliasesForOutlet(Outlet $outlet): array
    {
        $name = self::slug((string) $outlet->name);
        $code = self::slug((string) $outlet->code);
        $hrOutletId = self::slug((string) ($outlet->hr_outlet_id ?? ''));

        $aliases = [
            $name,
            strtolower((string) $outlet->code),
            $code,
            $hrOutletId,
        ];

        $plainName = trim((string) $outlet->name);
        if ($plainName !== '') {
            $aliases[] = self::slug(preg_replace('/^\s*TKJ\s*,\s*/i', '', $plainName) ?? $plainName);

            $parts = preg_split('/,/', $plainName) ?: [];
            $lastPart = trim((string) end($parts));
            if ($lastPart !== '') {
                $aliases[] = self::slug($lastPart);
            }
        }

        if (preg_match('/\bTKJ\b\s*,?\s*(.+)$/i', $plainName, $matches)) {
            $aliases[] = self::slug((string) ($matches[1] ?? ''));
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    private static function slug(string $value): string
    {
        return Str::slug($value);
    }
}
