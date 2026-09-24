<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrScheduleSpreadsheetService;
use App\Services\UserManagementService;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HrShiftScheduleSpreadsheetController extends Controller
{
    public function export(
        Request $request,
        UserAuthContextResolver $resolver,
        HrScheduleSpreadsheetService $spreadsheet,
    ) {
        $validator = Validator::make($request->query(), [
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Filter Export Schedule tidak valid.', 'HR_SCHEDULE_EXPORT_FILTER_INVALID', 422, $validator->errors()->toArray());
        }

        $outletId = (string) $request->query('outlet_id');
        if (! $this->isOutletAllowed($request, $resolver, $outletId)) {
            return ApiResponse::error('Penugasan berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
        }

        return $spreadsheet->export($outletId, (string) $request->query('month'));
    }

    public function import(
        Request $request,
        UserAuthContextResolver $resolver,
        HrScheduleSpreadsheetService $spreadsheet,
    ) {
        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'month' => ['required', 'date_format:Y-m'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:15360'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('File Import Schedule tidak valid.', 'HR_SCHEDULE_IMPORT_FILE_INVALID', 422, $validator->errors()->toArray());
        }

        $outletId = (string) $request->input('outlet_id');
        if (! $this->isOutletAllowed($request, $resolver, $outletId)) {
            return ApiResponse::error('Penugasan berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
        }

        $result = $spreadsheet->import(
            $request->file('file'),
            $outletId,
            (string) $request->input('month'),
            $request->user()?->id ? (string) $request->user()->id : null,
            $this->hasEffectivePermission($request, 'hr.schedule.create'),
            $this->hasEffectivePermission($request, 'hr.schedule.update'),
            $this->hasEffectivePermission($request, 'hr.schedule.delete'),
        );

        if (! ($result['success'] ?? false)) {
            return ApiResponse::error(
                'Import Schedule dibatalkan karena terdapat baris yang tidak valid. Tidak ada perubahan database yang disimpan.',
                'HR_SCHEDULE_IMPORT_INVALID',
                422,
                ['rows' => $result['errors'] ?? []],
                $result,
            );
        }

        return ApiResponse::ok($result, 'Import Mapping Schedule berhasil.');
    }

    private function hasEffectivePermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (($menu['can_create'] ?? false) && ($menu['permission_create'] ?? null) === $permission) return true;
            if (($menu['can_edit'] ?? false) && ($menu['permission_update'] ?? null) === $permission) return true;
            if (($menu['can_delete'] ?? false) && ($menu['permission_delete'] ?? null) === $permission) return true;
            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
        }

        return false;
    }

    private function allowedOutletQuery(Request $request, UserAuthContextResolver $resolver)
    {
        $ctx = $resolver->resolve($request->user());
        $query = DB::table('outlets')
            ->whereIn(DB::raw("LOWER(COALESCE(type, 'outlet'))"), ['outlet', 'headquarter', 'warehouse'])
            ->where('is_active', true);

        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE' && filled($ctx['resolved_outlet_id'] ?? null)) {
            $query->where('id', (string) $ctx['resolved_outlet_id']);
        } elseif (($ctx['scope_mode'] ?? 'NONE') === 'NONE') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function isOutletAllowed(Request $request, UserAuthContextResolver $resolver, string $outletId): bool
    {
        if (! Schema::hasTable('outlets')) return false;
        return (clone $this->allowedOutletQuery($request, $resolver))->where('id', $outletId)->exists();
    }
}
