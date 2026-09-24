<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_uniform_outbounds')) return;

        $addCancelledAt = ! Schema::hasColumn('HR_uniform_outbounds', 'cancelled_at');
        $addCancelledBy = ! Schema::hasColumn('HR_uniform_outbounds', 'cancelled_by_user_id');
        $addCancelReason = ! Schema::hasColumn('HR_uniform_outbounds', 'cancel_reason');
        if (! $addCancelledAt && ! $addCancelledBy && ! $addCancelReason) return;

        Schema::table('HR_uniform_outbounds', function (Blueprint $table) use ($addCancelledAt, $addCancelledBy, $addCancelReason): void {
            if ($addCancelledAt) $table->timestamp('cancelled_at')->nullable()->after('posted_at');
            if ($addCancelledBy) $table->ulid('cancelled_by_user_id')->nullable()->after('cancelled_at');
            if ($addCancelReason) $table->text('cancel_reason')->nullable()->after('cancelled_by_user_id');
        });
    }

    public function down(): void
    {
        // Non-destructive by design. Patch HR iteration berikutnya tidak boleh
        // menghapus audit trail atau merusak transaksi yang sudah terbentuk.
    }
};
