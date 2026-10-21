<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class HrV13I08SquadClassificationController extends Controller
{
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'classification' => ['required', 'string', Rule::in(['squad', 'non_squad'])],
        ]);
        if (! Schema::hasTable('HR_squads') || ! Schema::hasColumn('HR_squads', 'hr_is_non_squad')
            || ! Schema::hasColumn('HR_squads', 'hr_classified_at')
            || ! Schema::hasTable('hr_v13_i08_squad_classification_audits')) {
            return ApiResponse::error('Migrasi V13 I08 belum dijalankan.', 'MIGRATION_REQUIRED', 409);
        }

        $result = DB::transaction(function () use ($request, $id, $data) {
            $squad = DB::table('HR_squads')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $squad) return null;

            // Respect outlet scope resolved by auth:sanctum + outlet_scope middleware.
            $scopeId = $request->attributes->get('outlet_scope_id');
            if ($request->attributes->get('outlet_scope_locked') && blank($scopeId)) {
                abort(403, 'Outlet scope terkunci dan tidak memiliki outlet yang valid.');
            }
            if (is_string($scopeId) && trim($scopeId) !== '') {
                $scopeOutlet = Schema::hasTable('outlets') ? DB::table('outlets')->where('id', $scopeId)->first() : null;
                $allowed = [strtolower(trim($scopeId))];
                if ($scopeOutlet) {
                    foreach (['name', 'code', 'hr_outlet_id'] as $key) {
                        if (property_exists($scopeOutlet, $key) && filled($scopeOutlet->{$key})) {
                            $allowed[] = strtolower(trim((string) $scopeOutlet->{$key}));
                        }
                    }
                }
                if (! in_array(strtolower(trim((string) ($squad->assignment ?? ''))), $allowed, true)) {
                    abort(403, 'Squad di luar outlet scope yang dipilih.');
                }
            }
            $desired = $data['classification'] === 'non_squad';
            $old = (bool) ($squad->hr_is_non_squad ?? false);
            if ($old === $desired) {
                return ['id' => $id, 'classification' => $desired ? 'non_squad' : 'squad', 'changed' => false];
            }

            $now = now();
            DB::table('HR_squads')->where('id', $id)->update([
                'hr_is_non_squad' => $desired,
                'hr_classified_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('hr_v13_i08_squad_classification_audits')->insert([
                'squad_id' => $id,
                'actor_user_id' => (string) ($request->user()?->id ?? ''),
                'from_classification' => $old ? 'non_squad' : 'squad',
                'to_classification' => $desired ? 'non_squad' : 'squad',
                'source' => 'data_squad',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['id' => $id, 'classification' => $desired ? 'non_squad' : 'squad', 'changed' => true];
        }, 3);
        if ($result === null) return ApiResponse::error('Data Squad tidak ditemukan.', 'NOT_FOUND', 404);
        return ApiResponse::ok($result, 'Klasifikasi Data Squad diperbarui tanpa menghapus user dan histori HR.');
    }
}
