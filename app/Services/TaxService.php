<?php

namespace App\Services;

use App\Models\Tax;
use Illuminate\Support\Facades\DB;

class TaxService
{
    public function getActiveDefault(): ?Tax
    {
        $this->ensureFallbackDefault();

        return Tax::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Enforce invariant:
     * - Only one active tax may be default
     * - Setting a tax as default will unset others atomically
     */
    public function enforceDefaultInvariant(Tax $tax): Tax
    {
        return DB::transaction(function () use ($tax) {
            // If inactive, cannot be default
            if (! $tax->is_active && $tax->is_default) {
                $tax->is_default = false;
                $tax->save();
            }

            if ($tax->is_active && $tax->is_default) {
                Tax::query()
                    ->where('id', '!=', $tax->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            return $tax->fresh();
        });
    }

    public function stabilize(Tax $tax): Tax
    {
        return DB::transaction(function () use ($tax) {
            $tax = $this->enforceDefaultInvariant($tax);
            $fallback = $this->ensureFallbackDefault();

            if ($fallback && (string) $fallback->id === (string) $tax->id) {
                return $fallback->fresh();
            }

            return $tax->fresh();
        });
    }

    public function setDefault(Tax $tax): Tax
    {
        $tax->is_default = true;
        $tax->is_active = true;
        $tax->save();

        return $this->stabilize($tax);
    }

    public function updateActiveStatus(Tax $tax, bool $isActive): Tax
    {
        return DB::transaction(function () use ($tax, $isActive) {
            $tax->is_active = $isActive;
            if (! $isActive) {
                $tax->is_default = false;
            } elseif (! Tax::query()->where('is_active', true)->where('is_default', true)->where('id', '!=', $tax->id)->exists()) {
                $tax->is_default = true;
            }

            $tax->save();

            return $this->stabilize($tax);
        });
    }

    public function ensureFallbackDefault(): ?Tax
    {
        $existing = Tax::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $fallback = Tax::query()
            ->where('is_active', true)
            ->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('updated_at')
            ->orderBy('created_at')
            ->first();

        if (! $fallback) {
            return null;
        }

        $fallback->is_default = true;
        $fallback->save();

        return $this->enforceDefaultInvariant($fallback);
    }
}
