<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrV13I04OutletCompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class HrV13I04OutletCompanyController extends Controller
{
    public function __construct(private readonly HrV13I04OutletCompanyService $service) {}

    public function index(): mixed
    {
        return ApiResponse::ok($this->service->snapshot(), 'OK');
    }

    public function updateOutlet(Request $request, string $id): mixed
    {
        $data = $request->validate([
            'hr_region' => ['present','nullable','string',Rule::in(HrV13I04OutletCompanyService::REGIONS)],
            'company_code' => ['present','nullable','string','max:16'],
            'expected_company_code' => ['present','nullable','string','max:16'],
            'allow_reassignment' => ['sometimes','boolean'],
        ]);
        $this->service->updateOutlet($id, true, $data['hr_region'], true, $data['company_code'],
            (bool) ($data['allow_reassignment'] ?? false), $data['expected_company_code'],
            $request->user()?->id, 'hr-outlet-form');
        return ApiResponse::ok(['outlet_id' => $id], 'Wilayah dan PT disimpan.');
    }

    public function updateChecklist(Request $request, string $code): mixed
    {
        $data = $request->validate([
            'outlet_ids' => ['required','array','max:10000'],
            'outlet_ids.*' => ['string','max:26','distinct'],
            'expected_revision' => ['required','string','size:64'],
            'confirm_changes' => ['required','accepted'],
            'allow_reassignment' => ['sometimes','boolean'],
        ]);
        $result = $this->service->assignCompany($code, $data['outlet_ids'], $data['expected_revision'],
            (bool) ($data['allow_reassignment'] ?? false), $request->user()?->id);
        return ApiResponse::ok($result, 'Checklist outlet PT berhasil disimpan.');
    }

    public function audit(Request $request): mixed
    {
        $query = DB::table('hr_v13_i04_pt_mapping_audits')->orderByDesc('created_at');
        if ($request->filled('outlet_id')) $query->where('outlet_id', $request->query('outlet_id'));
        return ApiResponse::ok(['items' => $query->limit(100)->get()], 'OK');
    }
}
