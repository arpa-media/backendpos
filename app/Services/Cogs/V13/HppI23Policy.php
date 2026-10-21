<?php

namespace App\Services\Cogs\V13;

/** Pure deterministic policy shared by overview, detail and tests. */
final class HppI23Policy
{
    public static function candidateKeys(?string $modifierId, ?string $noteId): array
    {
        $keys = [];
        if ($noteId !== null && $noteId !== '') $keys[] = $noteId;
        if ($modifierId !== null && $modifierId !== '') $keys[] = $modifierId;
        $keys[] = 'BASE';
        return array_values(array_unique($keys));
    }

    public static function pick(array $scoped, ?string $modifierId, ?string $noteId, ?object $legacy): ?array
    {
        foreach (self::candidateKeys($modifierId, $noteId) as $key) {
            if (isset($scoped[$key])) return ['kind' => 'scoped', 'row' => $scoped[$key], 'key' => $key];
        }
        return $legacy ? ['kind' => 'legacy', 'row' => $legacy, 'key' => 'LEGACY'] : null;
    }

    /** Never treat missing stock/price as zero-cost completed HPP. */
    public static function status(bool $hasRecipe, int $itemCount, int $unpriced, int $invalid, int $noStock): string
    {
        if (! $hasRecipe) return 'missing_recipe';
        if ($itemCount === 0 || $invalid > 0) return 'invalid_recipe';
        if ($unpriced > 0) return 'missing_cost';
        if ($noStock > 0) return 'insufficient_stock';
        return 'estimated';
    }
}
