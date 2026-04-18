<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_daily_summary_refresh_queue')) {
            return;
        }

        Schema::create('report_daily_summary_refresh_queue', function (Blueprint $table) {
            $table->char('outlet_id', 26);
            $table->date('business_date');
            $table->string('business_timezone', 64)->nullable();
            $table->string('reason', 80)->default('manual');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('touch_count')->default(1);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_touched_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->primary(['outlet_id', 'business_date'], 'report_daily_summary_refresh_queue_pk');
            $table->index(['status', 'queued_at'], 'report_daily_summary_refresh_queue_status_idx');
            $table->index(['business_date', 'status'], 'report_daily_summary_refresh_queue_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_summary_refresh_queue');
    }
};
