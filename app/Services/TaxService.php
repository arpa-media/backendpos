<?php

namespace App\Services;

use App\Models\Tax;
use Illuminate\Support\Facades\DB;

class TaxService
{
    public function getActiveDefault(): ?Tax
    {
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
            if (!$tax->is_active && $tax->is_default) {
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

    public function setDefault(Tax $tax): Tax
    {
        $tax->is_default = true;
        $tax->is_active = true;
        $tax->save();
        return $this->enforceDefaultInvariant($tax);
    }
}
