<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REALIZATION_TABLES = [
        'pur_service_entry_sheets',
        'pur_goods_receipts',
        'pur_service_acceptances',
        'pur_reimburse_payments',
    ];

    public function up(): void
    {
        foreach (self::REALIZATION_TABLES as $table) {
            if (! Schema::hasTable($table)) continue;

            Schema::table($table, function (Blueprint $t) use ($table): void {
                if (! Schema::hasColumn($table, 'workflow_stage')) $t->string('workflow_stage', 32)->default('REVIEW')->index();
                if (! Schema::hasColumn($table, 'reviewed_by_user_id')) $t->char('reviewed_by_user_id', 26)->nullable()->index();
                if (! Schema::hasColumn($table, 'reviewed_at')) $t->timestamp('reviewed_at')->nullable();
                if (! Schema::hasColumn($table, 'evidence_submitted_by_user_id')) $t->char('evidence_submitted_by_user_id', 26)->nullable()->index();
                if (! Schema::hasColumn($table, 'evidence_submitted_at')) $t->timestamp('evidence_submitted_at')->nullable();
            });

            DB::table($table)->whereIn('status', ['POSTED', 'APPROVED'])->update(['workflow_stage' => 'POSTED']);
            DB::table($table)->where('status', 'AWAITING_APPROVAL')->update(['workflow_stage' => 'APPROVAL']);
            DB::table($table)->where('status', 'DRAFT')->where(function ($q): void {
                $q->whereNull('workflow_stage')->orWhereNotIn('workflow_stage', ['REVIEW', 'EVIDENCE', 'APPROVAL']);
            })->update(['workflow_stage' => 'REVIEW']);
        }

        // Manual Journal is merged into General Posting. Keep the legacy route/API
        // for compatibility, but remove the standalone sidebar menu.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'finance-manual-journal')->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'finance-manual-journal')->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        // Columns intentionally retained on rollback to preserve workflow audit history.
    }
};
