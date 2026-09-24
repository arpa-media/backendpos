<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            return;
        }

        Schema::table('cogs_purchasing_cost_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'canonical_receipt_fingerprint')) {
                $table->char('canonical_receipt_fingerprint', 64)->nullable()->after('source_snapshot');
                $table->index('canonical_receipt_fingerprint', 'cogs_snap_canon_receipt_idx');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'canonical_line_fingerprint')) {
                $table->char('canonical_line_fingerprint', 64)->nullable()->after('canonical_receipt_fingerprint');
                $table->index('canonical_line_fingerprint', 'cogs_snap_canon_line_idx');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'canonical_source_type')) {
                $table->string('canonical_source_type', 40)->nullable()->after('canonical_line_fingerprint');
                $table->index('canonical_source_type', 'cogs_snap_canon_type_idx');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'canonical_source_id')) {
                $table->string('canonical_source_id', 80)->nullable()->after('canonical_source_type');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'source_role')) {
                $table->string('source_role', 30)->default('physical')->after('canonical_source_id');
                $table->index('source_role', 'cogs_snap_source_role_idx');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
                $table->boolean('is_canonical')->default(true)->after('source_role');
                $table->index('is_canonical', 'cogs_snap_is_canon_idx');
            }
            if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', 'duplicate_of_snapshot_id')) {
                $table->ulid('duplicate_of_snapshot_id')->nullable()->after('is_canonical');
                $table->index('duplicate_of_snapshot_id', 'cogs_snap_duplicate_idx');
            }
        });

        // Existing Purchasing Stock Request snapshots are procurement/accounting mirrors.
        // Hide them from canonical History/COGS immediately without deleting audit rows.
        DB::table('cogs_purchasing_cost_snapshots')
            ->where('receipt_type_snapshot', 'manual')
            ->whereNotNull('stock_request_id')
            ->where('supplier_document_number_snapshot', 'like', 'PUR-EXEC:%')
            ->update([
                'source_role' => 'procurement_mirror',
                'is_canonical' => false,
                'canonical_source_type' => 'warehouse_stock_request_pending',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive by design. Canonical identity is accounting/COGS audit metadata.
    }
};
