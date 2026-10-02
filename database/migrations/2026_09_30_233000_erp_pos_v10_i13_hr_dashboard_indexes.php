<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('HR_attendances', ['business_date', 'late_minutes'], 'hr_att_i13_date_late_idx');
        $this->addIndex('HR_payroll_cutoffs', ['status', 'finalized_at'], 'hr_pay_cut_i13_final_idx');
        $this->addIndex('HR_bonus_projections', ['status', 'finalized_at', 'outlet_id'], 'hr_bonus_i13_final_idx');
        $this->addIndex('HR_applications', ['created_at', 'recruitment_position_id'], 'hr_app_i13_created_pos_idx');
    }

    public function down(): void
    {
        $this->dropIndex('HR_attendances', 'hr_att_i13_date_late_idx');
        $this->dropIndex('HR_payroll_cutoffs', 'hr_pay_cut_i13_final_idx');
        $this->dropIndex('HR_bonus_projections', 'hr_bonus_i13_final_idx');
        $this->dropIndex('HR_applications', 'hr_app_i13_created_pos_idx');
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->hasIndex($table, $name)) return;
        foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE `Key_name` = ?', [$name])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
