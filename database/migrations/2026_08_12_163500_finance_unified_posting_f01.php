<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        foreach (['finance_posting_templates','finance_posting_template_lines','finance_general_postings','finance_general_posting_journals','finance_journal_entries','finance_journal_entry_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Finance Unified Posting F01 membutuhkan {$table}.");
            }
        }

        $now = now();
        $existing = Schema::hasColumn('finance_posting_templates', 'system_key')
            ? DB::table('finance_posting_templates')->where('system_key', 'GENERAL_AUTO_SNAPSHOT')->first()
            : null;
        $existing = $existing ?: DB::table('finance_posting_templates')->where('code', 'SYS-GENERAL-AUTO-SNAPSHOT')->first();
        $id = (string) ($existing->id ?? Str::ulid());

        $payload = [
            'code' => 'SYS-GENERAL-AUTO-SNAPSHOT',
            'name' => 'System · Unified Auto Posting Snapshot',
            'source_type' => 'GENERAL',
            'company_code' => null,
            'outlet_id' => null,
            'marking' => null,
            'description' => 'System-only template marker. Debit/credit AUTO disimpan immutable sebagai snapshot pada General Posting metadata, bukan dari template lines.',
            'is_active' => true,
            'deleted_at' => null,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('finance_posting_templates', 'system_key')) $payload['system_key'] = 'GENERAL_AUTO_SNAPSHOT';
        if (Schema::hasColumn('finance_posting_templates', 'is_system')) $payload['is_system'] = true;
        if (Schema::hasColumn('finance_posting_templates', 'manual_selectable')) $payload['manual_selectable'] = false;

        if ($existing) {
            DB::table('finance_posting_templates')->where('id', $id)->update($payload);
        } else {
            DB::table('finance_posting_templates')->insert($payload + [
                'id' => $id,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
            ]);
        }

        // Snapshot template intentionally has no formula lines. AUTO lines live in the
        // General Posting snapshot and are validated/balanced before GL creation.
        DB::table('finance_posting_template_lines')->where('template_id', $id)->delete();
    }

    public function down(): void
    {
        // Keep system marker/template for audit compatibility with historical General Posting rows.
    }
};
