<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_sync_runs')) {
            return;
        }

        Schema::table('hr_sync_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_sync_runs', 'contract')) {
                $table->string('contract')->nullable()->after('id');
            }

            if (! Schema::hasColumn('hr_sync_runs', 'version')) {
                $table->unsignedInteger('version')->nullable()->after('contract');
            }

            if (Schema::hasColumn('hr_sync_runs', 'dry_run') && ! Schema::hasColumn('hr_sync_runs', 'is_dry_run')) {
                $table->boolean('is_dry_run')->default(false)->after('exported_at');
            }

            if (Schema::hasColumn('hr_sync_runs', 'summary') && ! Schema::hasColumn('hr_sync_runs', 'summary_json')) {
                $table->longText('summary_json')->nullable()->after('is_dry_run');
            }
        });

        if (Schema::hasColumn('hr_sync_runs', 'dry_run') && Schema::hasColumn('hr_sync_runs', 'is_dry_run')) {
            DB::statement('UPDATE hr_sync_runs SET is_dry_run = dry_run WHERE is_dry_run IS NULL OR is_dry_run = 0');
        }

        if (Schema::hasColumn('hr_sync_runs', 'summary') && Schema::hasColumn('hr_sync_runs', 'summary_json')) {
            DB::statement('UPDATE hr_sync_runs SET summary_json = summary WHERE summary_json IS NULL');
        }

        Schema::table('hr_sync_runs', function (Blueprint $table) {
            if (Schema::hasColumn('hr_sync_runs', 'dry_run')) {
                $table->dropColumn('dry_run');
            }

            if (Schema::hasColumn('hr_sync_runs', 'summary')) {
                $table->dropColumn('summary');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_sync_runs')) {
            return;
        }

        Schema::table('hr_sync_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_sync_runs', 'dry_run')) {
                $table->boolean('dry_run')->default(false)->after('exported_at');
            }

            if (! Schema::hasColumn('hr_sync_runs', 'summary')) {
                $table->longText('summary')->nullable()->after('dry_run');
            }
        });

        if (Schema::hasColumn('hr_sync_runs', 'is_dry_run') && Schema::hasColumn('hr_sync_runs', 'dry_run')) {
            DB::statement('UPDATE hr_sync_runs SET dry_run = is_dry_run WHERE dry_run IS NULL OR dry_run = 0');
        }

        if (Schema::hasColumn('hr_sync_runs', 'summary_json') && Schema::hasColumn('hr_sync_runs', 'summary')) {
            DB::statement('UPDATE hr_sync_runs SET summary = summary_json WHERE summary IS NULL');
        }

        Schema::table('hr_sync_runs', function (Blueprint $table) {
            if (Schema::hasColumn('hr_sync_runs', 'summary_json')) {
                $table->dropColumn('summary_json');
            }

            if (Schema::hasColumn('hr_sync_runs', 'is_dry_run')) {
                $table->dropColumn('is_dry_run');
            }

            if (Schema::hasColumn('hr_sync_runs', 'version')) {
                $table->dropColumn('version');
            }

            if (Schema::hasColumn('hr_sync_runs', 'contract')) {
                $table->dropColumn('contract');
            }
        });
    }
};
