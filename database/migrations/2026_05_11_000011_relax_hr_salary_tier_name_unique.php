<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('HR_salary_tiers')) {
            return;
        }

        try {
            $indexes = DB::select("SHOW INDEX FROM `HR_salary_tiers` WHERE Key_name = 'HR_salary_tiers_name_unique'");
            if (!empty($indexes)) {
                DB::statement('ALTER TABLE `HR_salary_tiers` DROP INDEX `HR_salary_tiers_name_unique`');
            }
        } catch (Throwable $e) {
            // Aman diabaikan: controller sudah restore soft-deleted tier dengan nama yang sama.
        }
    }

    public function down(): void
    {
        // Tidak dibuat ulang agar data soft-delete lama tidak kembali mengunci input nama yang sama.
    }
};
