<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrOutletStaffingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HrOutletStaffingController extends Controller
{
    public function __construct(private readonly HrOutletStaffingService $staffing)
    {
    }

    public function employees(Request $request, string $id)
    {
        if (! Schema::hasTable('outlets')) return ApiResponse::error('Tabel outlet belum tersedia.', 'TABLE_MISSING', 409);
        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (! $outlet) return ApiResponse::error('Outlet tidak ditemukan.', 'NOT_FOUND', 404);

        $role = $this->staffing->normalizeRoleName($request->query('role'));
        if ($role === '') return ApiResponse::error('Role/jabatan wajib dipilih.', 'VALIDATION_ERROR', 422, ['role' => ['Role/jabatan wajib dipilih.']]);

        return ApiResponse::ok($this->staffing->employeesForRole($outlet, $role), 'OK');
    }

    public function updateSlots(Request $request, string $id)
    {
        if (! Schema::hasTable('outlets')) return ApiResponse::error('Tabel outlet belum tersedia.', 'TABLE_MISSING', 409);
        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (! $outlet) return ApiResponse::error('Outlet tidak ditemukan.', 'NOT_FOUND', 404);

        $validator = Validator::make($request->all(), [
            'slots' => ['required', 'array', 'max:150'],
            'slots.*.role' => ['required', 'string', 'max:150'],
            'slots.*.slot' => ['required', 'integer', 'min:0', 'max:5000'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi staffing slot gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        try {
            $data = $this->staffing->updateSlots($outlet, $validator->validated()['slots'], $request->user()?->id ? (string) $request->user()->id : null);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFFING_TABLE_MISSING', 409);
        }

        return ApiResponse::ok($data, 'Staffing slot berhasil diperbarui.');
    }
}
