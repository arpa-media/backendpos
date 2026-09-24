<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_portals')) {
            $console = DB::table('access_portals')->where('code', 'console')->first();
            if ($console) {
                $maxOtherSort = (int) (DB::table('access_portals')->where('code', '!=', 'console')->max('sort_order') ?? 0);
                DB::table('access_portals')->where('id', $console->id)->update([
                    'name' => 'Console',
                    'description' => 'System console untuk observability, reporting control, storage file management, dan maintenance.',
                    'sort_order' => max(999999, $maxOtherSort + 1000),
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where(function ($query): void {
                    $query->where('code', 'finance-i08-expense-report')
                        ->orWhere('path', '/finance/expense-report');
                })
                ->update(['name' => 'Expense Report', 'updated_at' => now()]);

            DB::table('access_menus')
                ->where('code', 'console-maintenance')
                ->update([
                    'name' => 'Maintenance',
                    'path' => '/console/maintenance',
                    'sort_order' => 40,
                    'permission_view' => 'console.maintenance.view',
                    'permission_update' => 'console.maintenance.manage',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive integrity repair. Do not roll back canonical labels,
        // Console ordering, or Access Matrix metadata after users rely on them.
    }
};
