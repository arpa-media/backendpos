<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchasingDocumentScopeService
{
    public const SCOPE_COMPANY = 'COMPANY';
    public const SCOPE_OUTLET = 'OUTLET';
    public const DEFAULT_MARKING = 'UNMARKING';
    public const MARKINGS = ['MARKING', 'UNMARKING'];

    /** @var array<string, string|null> */
    private array $companyNameCache = [];

    /** @return array<int, array{code:string,name:string,legal_name:?string}> */
    public function companies(): array
    {
        if (! Schema::hasTable('finance_companies')) {
            return [];
        }

        return DB::table('finance_companies')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'legal_name'])
            ->map(fn ($row): array => [
                'code' => strtoupper((string) $row->code),
                'name' => (string) $row->name,
                'legal_name' => $row->legal_name ? (string) $row->legal_name : null,
            ])->values()->all();
    }

    public function companyName(?string $companyCode): ?string
    {
        $code = strtoupper(trim((string) $companyCode));
        if ($code === '') {
            return null;
        }
        if (array_key_exists($code, $this->companyNameCache)) {
            return $this->companyNameCache[$code];
        }
        if (! Schema::hasTable('finance_companies')) {
            return $this->companyNameCache[$code] = null;
        }

        $name = DB::table('finance_companies')->where('code', $code)->value('name');
        return $this->companyNameCache[$code] = ($name ? (string) $name : null);
    }

    /**
     * Resolve economic/accounting scope independently from organizational chamber.
     * Existing automatic Stock flows may omit scope_type/company_code; OUTLET is inferred.
     *
     * @param array<string,mixed> $data
     * @return array{scope_type:string,company_code:?string,outlet_id:?string,marking:string}
     */
    public function resolve(array $data): array
    {
        $requestType = strtoupper(trim((string) ($data['request_type'] ?? '')));
        $chamber = strtoupper(trim((string) ($data['chamber_code'] ?? '')));
        $outletId = trim((string) ($data['outlet_id'] ?? '')) ?: null;
        $scopeType = strtoupper(trim((string) ($data['scope_type'] ?? '')));
        $companyCode = strtoupper(trim((string) ($data['company_code'] ?? '')));
        $requestDate = trim((string) ($data['request_date'] ?? '')) ?: now('Asia/Jakarta')->toDateString();
        $marking = strtoupper(trim((string) ($data['marking'] ?? self::DEFAULT_MARKING))) ?: self::DEFAULT_MARKING;
        $automaticSource = trim((string) ($data['source_key'] ?? $data['source_type'] ?? '')) !== '';

        if ($scopeType === '') {
            $scopeType = ($requestType === FundRequest::TYPE_STOCK || $outletId)
                ? self::SCOPE_OUTLET
                : self::SCOPE_COMPANY;
        }

        if (! in_array($scopeType, [self::SCOPE_COMPANY, self::SCOPE_OUTLET], true)) {
            throw ValidationException::withMessages(['scope_type' => 'Scope Fund Request harus COMPANY atau OUTLET.']);
        }
        if (! in_array($marking, self::MARKINGS, true)) {
            throw ValidationException::withMessages(['marking' => 'Marking Purchasing harus MARKING atau UNMARKING.']);
        }

        if ($requestType === FundRequest::TYPE_STOCK && ($scopeType !== self::SCOPE_OUTLET || ! $outletId || $chamber !== 'OUTLET')) {
            throw ValidationException::withMessages([
                'scope_type' => 'Fund Request Stock wajib menggunakan scope Outlet.',
                'chamber_code' => 'Fund Request Stock wajib menggunakan Chamber Outlet.',
                'outlet_id' => 'Outlet wajib dipilih untuk Fund Request Stock.',
            ]);
        }
        if ($chamber === 'OUTLET' && $scopeType !== self::SCOPE_OUTLET) {
            throw ValidationException::withMessages(['scope_type' => 'Chamber Outlet wajib menggunakan scope Outlet.']);
        }

        if ($scopeType === self::SCOPE_COMPANY) {
            if ($outletId) {
                throw ValidationException::withMessages(['outlet_id' => 'Outlet tidak boleh diisi ketika scope Fund Request adalah Company.']);
            }
            if ($companyCode === '') {
                if ($automaticSource) {
                    // Legacy/automatic bridges historically had no economic company scope.
                    // Keep them operational; manual API requests are still forced by FormRequest
                    // to choose an active company before they reach this resolver.
                    return [
                        'scope_type' => self::SCOPE_COMPANY,
                        'company_code' => null,
                        'outlet_id' => null,
                        'marking' => $marking,
                    ];
                }
                throw ValidationException::withMessages(['company_code' => 'PT wajib dipilih untuk scope Company.']);
            }
            $this->assertActiveCompany($companyCode);

            return [
                'scope_type' => self::SCOPE_COMPANY,
                'company_code' => $companyCode,
                'outlet_id' => null,
                'marking' => $marking,
            ];
        }

        if (! $outletId) {
            throw ValidationException::withMessages(['outlet_id' => 'Outlet wajib dipilih untuk scope Outlet.']);
        }
        if (! Schema::hasTable('outlets') || ! DB::table('outlets')->where('id', $outletId)->exists()) {
            throw ValidationException::withMessages(['outlet_id' => 'Outlet yang dipilih tidak ditemukan.']);
        }

        $resolvedCompany = $automaticSource
            ? $this->companyForOutletOrNull($outletId, $requestDate)
            : $this->companyForOutlet($outletId, $requestDate);

        return [
            'scope_type' => self::SCOPE_OUTLET,
            'company_code' => $resolvedCompany,
            'outlet_id' => $outletId,
            'marking' => $marking,
        ];
    }

    public function companyForOutlet(string $outletId, string $date): string
    {
        if (! Schema::hasTable('finance_outlet_company_mappings')) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Mapping PT Outlet belum tersedia pada Finance → Mapping PT Outlet.',
            ]);
        }

        $mapping = DB::table('finance_outlet_company_mappings')
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->first(['company_code']);

        $companyCode = strtoupper(trim((string) ($mapping->company_code ?? '')));
        if ($companyCode === '') {
            throw ValidationException::withMessages([
                'outlet_id' => 'Outlet belum dipetakan ke PT aktif pada Finance → Mapping PT Outlet.',
            ]);
        }
        $this->assertActiveCompany($companyCode);
        return $companyCode;
    }

    public function companyForOutletOrNull(string $outletId, string $date): ?string
    {
        try {
            return $this->companyForOutlet($outletId, $date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertActiveCompany(string $companyCode): void
    {
        if (! Schema::hasTable('finance_companies')
            || ! DB::table('finance_companies')->where('code', $companyCode)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'company_code' => "PT {$companyCode} tidak tersedia/aktif pada master Finance Company.",
            ]);
        }
    }
}
