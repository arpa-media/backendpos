<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_portals')) {
            return;
        }

        DB::table('access_portals')
            ->where('code', 'operational')
            ->update([
                'name' => 'Operational',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('access_portals')) {
            return;
        }

        DB::table('access_portals')
            ->where('code', 'operational')
            ->where('name', 'Operational')
            ->update([
                'name' => 'Chambers Operational',
                'updated_at' => now(),
            ]);
    }
};
