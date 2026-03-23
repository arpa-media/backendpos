<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('outlet_marking_settings')) {
            return;
        }

        Schema::table('outlet_marking_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('outlet_marking_settings', 'show_count')) {
                $table->unsignedInteger('show_count')->nullable()->after('interval_value');
            }
            if (!Schema::hasColumn('outlet_marking_settings', 'hide_count')) {
                $table->unsignedInteger('hide_count')->nullable()->after('show_count');
            }
        });

        DB::table('outlet_marking_settings')
            ->select(['id', 'status', 'interval_value', 'show_count', 'hide_count'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $status = strtoupper(trim((string) ($row->status ?? 'NORMAL')));
                    $legacy = max(1, (int) ($row->interval_value ?? 3));
                    $show = $row->show_count ? (int) $row->show_count : $legacy;
                    $hide = $row->hide_count
                        ? (int) $row->hide_count
                        : ($status === 'ACTIVE' ? $legacy : 1);

                    DB::table('outlet_marking_settings')
                        ->where('id', $row->id)
                        ->update([
                            'show_count' => $show,
                            'hide_count' => $hide,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('outlet_marking_settings')) {
            return;
        }

        Schema::table('outlet_marking_settings', function (Blueprint $table) {
            if (Schema::hasColumn('outlet_marking_settings', 'hide_count')) {
                $table->dropColumn('hide_count');
            }
            if (Schema::hasColumn('outlet_marking_settings', 'show_count')) {
                $table->dropColumn('show_count');
            }
        });
    }
};
