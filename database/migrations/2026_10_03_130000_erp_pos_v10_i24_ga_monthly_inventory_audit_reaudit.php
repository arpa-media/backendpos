<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_inventory_audits')) return;

        Schema::table('ga_inventory_audits', function (Blueprint $table): void {
            if (! Schema::hasColumn('ga_inventory_audits', 'audit_date')) {
                $table->date('audit_date')->nullable()->after('audit_month');
            }
            if (! Schema::hasColumn('ga_inventory_audits', 'audit_sequence')) {
                $table->unsignedSmallInteger('audit_sequence')->default(1)->after('audit_date');
            }
            if (! Schema::hasColumn('ga_inventory_audits', 're_audit_of_id')) {
                $table->ulid('re_audit_of_id')->nullable()->after('audit_sequence');
            }
        });

        foreach (DB::table('ga_inventory_audits')->whereNull('audit_date')->select(['id', 'started_at', 'created_at'])->get() as $row) {
            $source = $row->started_at ?: $row->created_at ?: now();
            DB::table('ga_inventory_audits')->where('id', $row->id)->update([
                'audit_date' => Carbon::parse($source)->toDateString(),
                'audit_sequence' => 1,
            ]);
        }

        // IMPORTANT (MySQL 1553): the legacy unique key starts with outlet_id and may be
        // the only index MySQL currently uses to support ga_inventory_audits_outlet_id_foreign.
        // Create the replacement period/sequence unique index FIRST. It also starts with
        // outlet_id, so the FK remains indexed when the legacy annual unique is dropped.
        // This ordering is intentionally idempotent for hosts where a previous I24 run
        // already added/backfilled the new columns but failed at DROP INDEX.
        if (! $this->hasIndex('ga_inventory_audits', 'ga_aud_period_seq_uq')) {
            Schema::table('ga_inventory_audits', function (Blueprint $table): void {
                $table->unique(['outlet_id', 'audit_year', 'audit_month', 'audit_sequence'], 'ga_aud_period_seq_uq');
            });
        }

        if ($this->hasIndex('ga_inventory_audits', 'ga_audit_outlet_year_uq')) {
            Schema::table('ga_inventory_audits', function (Blueprint $table): void {
                $table->dropUnique('ga_audit_outlet_year_uq');
            });
        }
        if (! $this->hasIndex('ga_inventory_audits', 'ga_aud_outlet_period_idx')) {
            Schema::table('ga_inventory_audits', function (Blueprint $table): void {
                $table->index(['outlet_id', 'audit_year', 'audit_month'], 'ga_aud_outlet_period_idx');
            });
        }
        if (! $this->hasForeign('ga_inventory_audits', 'ga_aud_reaudit_fk')) {
            Schema::table('ga_inventory_audits', function (Blueprint $table): void {
                $table->foreign('re_audit_of_id', 'ga_aud_reaudit_fk')
                    ->references('id')->on('ga_inventory_audits')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Monthly audit and re-audit history are audit evidence.
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] ?? null) === $name);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasForeign(string $table, string $name): bool
    {
        try {
            return collect(Schema::getForeignKeys($table))->contains(fn (array $foreign) => ($foreign['name'] ?? null) === $name);
        } catch (\Throwable) {
            return false;
        }
    }
};
