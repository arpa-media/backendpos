<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $posPortal = DB::table('access_portals')->where('code', 'pos')->first();
        if (!$posPortal) {
            $posPortalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $posPortalId,
                'code' => 'pos',
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $posPortalId = (string) $posPortal->id;
            DB::table('access_portals')->where('id', $posPortalId)->update([
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 10,
                'is_active' => true,
                'updated_at' => $now,
            ]);
        }

        DB::table('access_portals')->where('code', 'sales')->update([
            'name' => 'POS Outlet',
            'description' => 'Portal POS Outlet',
            'sort_order' => 20,
            'updated_at' => $now,
        ]);

        $salesPortalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        if (!$salesPortalId) {
            return;
        }

        $rolePortalRows = DB::table('access_role_portal_permissions')
            ->where('portal_id', $salesPortalId)
            ->get();

        foreach ($rolePortalRows as $row) {
            $exists = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where(function ($query) use ($row) {
                    if ($row->access_level_id) {
                        $query->where('access_level_id', $row->access_level_id);
                    } else {
                        $query->whereNull('access_level_id');
                    }
                })
                ->where('portal_id', $posPortalId)
                ->exists();

            if (!$exists) {
                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $row->access_role_id,
                    'access_level_id' => $row->access_level_id,
                    'portal_id' => $posPortalId,
                    'can_view' => (bool) $row->can_view,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $pathsToMove = [
            '/c/pos',
            '/c/cashier-report',
            '/c/printer',
            '/receipts/:id/print',
            '/kitchen/:id/print',
            '/bar/:id/print',
            '/table/:id/print',
            '/pizza/:id/print',
            '/cashier-report/print',
        ];

        DB::table('access_menus')
            ->whereIn('path', $pathsToMove)
            ->update([
                'portal_id' => $posPortalId,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = now();
        $salesPortalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        $posPortalId = DB::table('access_portals')->where('code', 'pos')->value('id');

        if ($salesPortalId && $posPortalId) {
            $pathsToMove = [
                '/c/pos',
                '/c/cashier-report',
                '/c/printer',
                '/receipts/:id/print',
                '/kitchen/:id/print',
                '/bar/:id/print',
                '/table/:id/print',
                '/pizza/:id/print',
                '/cashier-report/print',
            ];

            DB::table('access_menus')
                ->whereIn('path', $pathsToMove)
                ->update([
                    'portal_id' => $salesPortalId,
                    'updated_at' => $now,
                ]);

            DB::table('access_role_portal_permissions')->where('portal_id', $posPortalId)->delete();
            DB::table('access_portals')->where('id', $posPortalId)->delete();
        }
    }
};
