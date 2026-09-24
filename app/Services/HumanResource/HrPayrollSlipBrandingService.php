<?php

namespace App\Services\HumanResource;

use Illuminate\Validation\ValidationException;

/**
 * Branding khusus slip payroll I03.
 *
 * Sengaja berdiri sendiri agar patch I03 tidak bergantung / menimpa file I02.
 * Identitas PT dan logo disalin dari canonical HR branding yang sama.
 */
final class HrPayrollSlipBrandingService
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

    public function branding(string $companyCode): array
    {
        $code = strtoupper(trim($companyCode));
        if (! isset(self::COMPANIES[$code])) {
            throw ValidationException::withMessages([
                'company_code' => ['PT slip gaji harus BKJB atau MDMF.'],
            ]);
        }

        return array_merge(self::COMPANIES[$code], [
            'brand_name' => 'TOKO KOPI JAYA',
            'chamber_name' => 'Human Resource Chambers',
        ]);
    }

    public function logoPath(): string
    {
        return base_path('storage/app/hr/payroll-i03/branding/logo-hr.png');
    }
}
