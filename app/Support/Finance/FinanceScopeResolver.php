<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FinanceScopeResolver
{
    /** Backward-compatible validation list for existing controllers using Rule::in(). */
    public const COMPANIES = ['BKJB', 'MDMF', 'APB'];

    /** Legacy fallback only when finance_companies has not been migrated yet. */
    public const FALLBACK_COMPANIES = self::COMPANIES;
    public const MARKINGS = ['MARKING', 'UNMARKING'];

    public function resolve(?string $companyCode, ?string $outletId): array
    {
        $outletId = $this->nullableString($outletId);
        $companyCode = strtoupper((string) $this->nullableString($companyCode));

        if ($outletId !== null) {
            if (! Schema::hasTable('finance_outlet_company_mappings')) {
                throw new InvalidArgumentException('Master mapping outlet → PT Finance belum tersedia.');
            }

            $today = now('Asia/Jakarta')->toDateString();
            $mapping = DB::table('finance_outlet_company_mappings')
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
                })
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                })
                ->first(['company_code']);

            if (! $mapping) {
                throw new InvalidArgumentException('Outlet belum dipetakan ke PT aktif pada Chart of Account → Mapping PT Outlet.');
            }

            $mappedCompany = strtoupper((string) $mapping->company_code);
            $this->assertActiveCompany($mappedCompany);
            if ($companyCode !== '' && $companyCode !== $mappedCompany) {
                throw new InvalidArgumentException("PT {$companyCode} tidak sesuai dengan mapping outlet. Outlet tersebut terdaftar pada PT {$mappedCompany}.");
            }

            return ['company_code' => $mappedCompany, 'outlet_id' => $outletId];
        }

        if ($companyCode === '') {
            throw new InvalidArgumentException('PT wajib dipilih.');
        }
        $this->assertActiveCompany($companyCode);

        return ['company_code' => $companyCode, 'outlet_id' => null];
    }

    public function companyForOutlet(?string $outletId): ?string
    {
        $outletId = $this->nullableString($outletId);
        if ($outletId === null || ! Schema::hasTable('finance_outlet_company_mappings')) {
            return null;
        }

        $company = DB::table('finance_outlet_company_mappings')
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->value('company_code');

        return $company ? strtoupper((string) $company) : null;
    }

    public function companies(): array
    {
        if (! Schema::hasTable('finance_companies')) {
            return array_map(fn (string $code) => ['code' => $code, 'name' => 'PT '.$code], self::FALLBACK_COMPANIES);
        }

        return DB::table('finance_companies')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'legal_name'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function activeCompanyCodes(): array
    {
        if (! Schema::hasTable('finance_companies')) {
            return self::FALLBACK_COMPANIES;
        }

        $codes = DB::table('finance_companies')
            ->where('is_active', true)
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->values()
            ->all();

        return $codes ?: self::FALLBACK_COMPANIES;
    }

    public function outletMappings(bool $onlyOutletType = false): array
    {
        if (! Schema::hasTable('outlets')) {
            return [];
        }

        $query = DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m', function ($join): void {
                $join->on('m.outlet_id', '=', 'o.id')->where('m.is_active', true);
            })
            ->where('o.is_active', true);

        if ($onlyOutletType && Schema::hasColumn('outlets', 'type')) {
            $query->whereRaw("LOWER(COALESCE(o.type, '')) = 'outlet'");
        }

        $columns = ['o.id', 'o.code', 'o.name', 'm.company_code'];
        if (Schema::hasColumn('outlets', 'type')) {
            $columns[] = 'o.type';
        }
        if (Schema::hasColumn('outlets', 'timezone')) {
            $columns[] = 'o.timezone';
        }

        return $query
            ->orderByRaw("CASE WHEN m.company_code IS NULL THEN 0 ELSE 1 END")
            ->orderBy('o.name')
            ->get($columns)
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) $row->name,
                'type' => (string) ($row->type ?? 'outlet'),
                'timezone' => (string) ($row->timezone ?? 'Asia/Jakarta'),
                'company_code' => $row->company_code ? strtoupper((string) $row->company_code) : null,
                'is_mapped' => (bool) $row->company_code,
            ])
            ->all();
    }

    private function assertActiveCompany(string $companyCode): void
    {
        if (! in_array($companyCode, $this->activeCompanyCodes(), true)) {
            $available = implode(', ', $this->activeCompanyCodes());
            throw new InvalidArgumentException("PT {$companyCode} tidak aktif/tidak dikenal. PT aktif: {$available}.");
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
