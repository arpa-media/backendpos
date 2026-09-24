<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinanceCompanyOutletMappingController extends Controller
{
    public function __construct(private readonly FinanceScopeResolver $scopeResolver)
    {
    }

    public function index()
    {
        return ApiResponse::ok([
            'companies' => $this->scopeResolver->companies(),
            'items' => $this->scopeResolver->outletMappings(false),
            'unmapped_count' => collect($this->scopeResolver->outletMappings(false))->where('is_mapped', false)->count(),
        ]);
    }

    public function update(Request $request, string $outletId)
    {
        $data = $request->validate([
            'company_code' => ['required', Rule::in(FinanceScopeResolver::COMPANIES)],
            'effective_from' => ['nullable', 'date'],
        ]);

        if (! Schema::hasTable('outlets') || ! DB::table('outlets')->where('id', $outletId)->exists()) {
            return ApiResponse::error('Outlet tidak ditemukan.', 'OUTLET_NOT_FOUND', 404);
        }

        $existing = DB::table('finance_outlet_company_mappings')->where('outlet_id', $outletId)->first();
        DB::table('finance_outlet_company_mappings')->updateOrInsert(
            ['outlet_id' => $outletId],
            [
                'id' => (string) ($existing->id ?? Str::ulid()),
                'company_code' => strtoupper((string) $data['company_code']),
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => null,
                'is_active' => true,
                'updated_by_user_id' => $request->user()?->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        return ApiResponse::ok([
            'outlet_id' => $outletId,
            'company_code' => strtoupper((string) $data['company_code']),
        ], 'Mapping outlet → PT berhasil disimpan.');
    }
}
