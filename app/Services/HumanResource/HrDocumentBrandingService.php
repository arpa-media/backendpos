<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrDocumentBrandingService
{
    private const COMPANIES = [
        'BKJB' => [
            'company_code' => 'BKJB',
            'company_name' => 'PT. Bhinneka Karya Jaya Bersama',
            'address' => 'Jl. Candi Penataran No.43 C, Malang, Jawa Timur',
            'phone' => '+62811 3399 9933',
            'email' => 'ptbkjb@gmail.com',
        ],
        'MDMF' => [
            'company_code' => 'MDMF',
            'company_name' => 'PT. Minuman Dan Makanan Favoritmu',
            'address' => 'Jl. Candi Penataran No.38 B, Malang, Jawa Timur',
            'phone' => '+62811 3399 9933',
            'email' => 'ptmdmf@gmail.com',
        ],
    ];

    public function options(): array
    {
        return array_values(array_map(fn (array $row) => [
            'code' => $row['company_code'],
            'name' => $row['company_name'],
        ], self::COMPANIES));
    }

    public function resolveCompanyCode(?string $explicitCompanyCode = null, ?string $outletId = null): string
    {
        $explicit = strtoupper(trim((string) $explicitCompanyCode));
        if ($explicit !== '') return $this->assertCompanyCode($explicit);

        if ($outletId && Schema::hasTable('finance_outlet_company_mappings')) {
            $mapped = DB::table('finance_outlet_company_mappings')
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->value('company_code');
            if ($mapped) return $this->assertCompanyCode((string) $mapped);
        }

        throw ValidationException::withMessages([
            'company_code' => ['PT dokumen tidak dapat ditentukan. Pilih BKJB/MDMF atau lengkapi Mapping PT Outlet pada Finance.'],
        ]);
    }

    public function branding(string $companyCode): array
    {
        $code = $this->assertCompanyCode($companyCode);
        return array_merge(self::COMPANIES[$code], [
            'brand_name' => 'TOKO KOPI JAYA',
            'chamber_name' => 'Human Resource Chambers',
            'signatory_name' => 'Ray Fanany Muhammad',
            'signatory_role' => 'Human Resource Development',
            'issue_city' => 'Malang',
            'logo_asset' => '/hr/logo-hr.png',
            'signature_asset' => '/hr/signature-ray.jpg',
            'logo_sha256' => $this->hashAsset('logo-hr.png'),
            'signature_sha256' => $this->hashAsset('signature-ray.jpg'),
        ]);
    }

    public function snapshot(string $companyCode): array
    {
        return $this->branding($companyCode);
    }

    public function assetPath(string $fileName): string
    {
        return base_path('storage/app/hr/branding/'.$fileName);
    }

    private function assertCompanyCode(string $companyCode): string
    {
        $code = strtoupper(trim($companyCode));
        if (! isset(self::COMPANIES[$code])) {
            throw ValidationException::withMessages(['company_code' => ['PT dokumen harus BKJB atau MDMF.']]);
        }
        return $code;
    }

    private function hashAsset(string $fileName): ?string
    {
        $path = $this->assetPath($fileName);
        return is_file($path) ? hash_file('sha256', $path) : null;
    }
}
