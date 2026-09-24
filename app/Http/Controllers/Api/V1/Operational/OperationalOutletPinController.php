<?php

namespace App\Http\Controllers\Api\V1\Operational;

use App\Http\Controllers\Controller;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalOutletPinController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasColumn('outlets', 'pos_delete_bill_pin')) {
            return response()->json(['message' => 'Kolom PIN outlet belum tersedia. Jalankan migration POS terlebih dahulu.'], 409);
        }

        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $query = DB::table('outlets')->orderBy('name');
        $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $ids);

        $rows = $query->get(['id','code','name','type','timezone','is_active','pos_delete_bill_pin'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? ''),
                'type' => (string) ($row->type ?? 'outlet'),
                'timezone' => (string) ($row->timezone ?? 'Asia/Jakarta'),
                'is_active' => (bool) ($row->is_active ?? true),
                'pos_delete_bill_pin' => preg_replace('/\D+/', '', (string) ($row->pos_delete_bill_pin ?: '0341')) ?: '0341',
            ])->all();

        return response()->json(['data' => ['items' => $rows, 'scope' => ['label' => (string) ($scope['label'] ?? ''), 'outlet_ids' => $ids]]]);
    }

    public function update(Request $request, string $outlet): JsonResponse
    {
        $validated = $request->validate([
            'pos_delete_bill_pin' => ['required', 'regex:/^\d{4,12}$/'],
        ]);

        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        if (! in_array($outlet, $ids, true)) {
            return response()->json(['message' => 'Outlet berada di luar scope akses user.'], 403);
        }

        $row = DB::table('outlets')->where('id', $outlet)->first(['id','code','name']);
        if (! $row) return response()->json(['message' => 'Outlet tidak ditemukan.'], 404);

        $pin = preg_replace('/\D+/', '', (string) $validated['pos_delete_bill_pin']) ?: '0341';
        DB::table('outlets')->where('id', $outlet)->update(['pos_delete_bill_pin' => $pin, 'updated_at' => now()]);

        return response()->json(['data' => [
            'id' => (string) $row->id,
            'code' => (string) ($row->code ?? ''),
            'name' => (string) ($row->name ?? ''),
            'pos_delete_bill_pin' => $pin,
        ], 'message' => 'PIN outlet berhasil diperbarui.']);
    }
}
