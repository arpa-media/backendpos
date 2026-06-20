<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('HR_squads')) {
            return;
        }

        DB::table('HR_squads')->whereIn('contract_type', ['Tetap', 'tetap', 'TETAPI', 'Tetapi', 'tetapi'])->update(['contract_type' => 'TETAP']);
        DB::table('HR_squads')->where('contract_type', 'spt')->update(['contract_type' => 'SPT']);
        DB::table('HR_squads')->where('contract_type', 'pkwt')->update(['contract_type' => 'PKWT']);
    }

    public function down(): void
    {
        // Tidak dirollback agar opsi kontrak tetap konsisten uppercase.
    }
};
