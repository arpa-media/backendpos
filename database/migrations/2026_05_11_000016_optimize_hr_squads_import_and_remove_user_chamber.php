<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('HR_squads')) {
            return;
        }

        if (Schema::hasColumn('HR_squads', 'user_chamber_name')) {
            Schema::table('HR_squads', function (Blueprint $table) {
                $table->dropColumn('user_chamber_name');
            });
        }

        $this->addIndexIfPossible(['status', 'role_name'], 'hr_squads_status_role_idx');
        $this->addIndexIfPossible(['status', 'assignment'], 'hr_squads_status_assignment_idx');
        $this->addIndexIfPossible(['employee_type', 'contract_type'], 'hr_squads_employee_contract_idx');
        $this->addIndexIfPossible(['updated_at'], 'hr_squads_updated_at_idx');
    }

    public function down(): void
    {
        if (!Schema::hasTable('HR_squads')) {
            return;
        }

        foreach (['hr_squads_updated_at_idx', 'hr_squads_employee_contract_idx', 'hr_squads_status_assignment_idx', 'hr_squads_status_role_idx'] as $indexName) {
            try {
                Schema::table('HR_squads', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (\Throwable $exception) {
                // Index mungkin tidak ada di environment tertentu.
            }
        }

        if (!Schema::hasColumn('HR_squads', 'user_chamber_name')) {
            Schema::table('HR_squads', function (Blueprint $table) {
                $table->string('user_chamber_name', 150)->nullable()->after('role_name');
            });
        }
    }

    private function addIndexIfPossible(array $columns, string $indexName): void
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn('HR_squads', $column)) {
                return;
            }
        }

        try {
            Schema::table('HR_squads', function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable $exception) {
            // Abaikan jika index sudah ada dari patch sebelumnya atau database punya nama index berbeda.
        }
    }
};
