<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('HR_attendances')) {
            return;
        }

        Schema::create('HR_attendances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->ulid('employee_id')->nullable();
            $table->ulid('assignment_outlet_id')->nullable();

            // One raw attendance record contains both check-in and check-out.
            $table->date('business_date');
            $table->string('attendance_timezone', 64)->default('Asia/Jakarta');
            $table->string('device_timezone', 64)->nullable();
            $table->string('assignment_timezone', 64)->nullable();
            $table->string('record_status', 30)->default('open'); // open|complete|cancelled

            $table->dateTime('checkin_at')->nullable()->comment('Stored as UTC');
            $table->ulid('checkin_outlet_id')->nullable();
            $table->string('checkin_outlet_name', 180)->nullable();
            $table->string('checkin_outlet_timezone', 64)->nullable();
            $table->decimal('checkin_lat', 10, 7)->nullable();
            $table->decimal('checkin_lng', 10, 7)->nullable();
            $table->decimal('checkin_accuracy_m', 10, 2)->nullable();
            $table->unsignedInteger('checkin_distance_m')->nullable();
            $table->unsignedInteger('checkin_radius_m')->nullable();
            $table->integer('checkin_radius_delta_m')->nullable();
            $table->boolean('checkin_inside_radius')->default(false);
            $table->string('checkin_location_status', 30)->default('outside');
            $table->string('checkin_mode', 30)->default('normal'); // normal|outside_radius|field_duty
            $table->text('checkin_note')->nullable();
            $table->string('duty_location', 255)->nullable();
            $table->string('checkin_photo_path', 255)->nullable();
            $table->string('checkin_camera_status', 30)->default('unavailable');
            $table->string('checkin_camera_note', 255)->nullable();

            $table->dateTime('checkout_at')->nullable()->comment('Stored as UTC');
            $table->date('checkout_business_date')->nullable();
            $table->string('checkout_timezone', 64)->nullable();
            $table->ulid('checkout_outlet_id')->nullable();
            $table->string('checkout_outlet_name', 180)->nullable();
            $table->string('checkout_outlet_timezone', 64)->nullable();
            $table->decimal('checkout_lat', 10, 7)->nullable();
            $table->decimal('checkout_lng', 10, 7)->nullable();
            $table->decimal('checkout_accuracy_m', 10, 2)->nullable();
            $table->unsignedInteger('checkout_distance_m')->nullable();
            $table->unsignedInteger('checkout_radius_m')->nullable();
            $table->integer('checkout_radius_delta_m')->nullable();
            $table->boolean('checkout_inside_radius')->nullable();
            $table->string('checkout_location_status', 30)->nullable();
            $table->string('checkout_mode', 30)->nullable();
            $table->text('checkout_note')->nullable();
            $table->string('checkout_duty_location', 255)->nullable();
            $table->string('checkout_photo_path', 255)->nullable();
            $table->string('checkout_camera_status', 30)->nullable();
            $table->string('checkout_camera_note', 255)->nullable();

            // Iterasi 04 consumes these flags for the two-stage approval workflow.
            $table->boolean('approval_required')->default(false);
            $table->string('approval_status', 30)->default('not_required');
            $table->boolean('calculation_eligible')->default(true);
            $table->json('exception_flags')->nullable();

            $table->text('device_info')->nullable();
            $table->string('source', 40)->default('attendance-portal');
            $table->timestamps();

            $table->unique(['user_id', 'business_date'], 'hr_att_user_date_uq');
            $table->index(['user_id', 'record_status', 'business_date'], 'hr_att_user_open_idx');
            $table->index(['checkin_outlet_id', 'business_date'], 'hr_att_outlet_date_idx');
            $table->index(['approval_required', 'approval_status'], 'hr_att_approval_idx');

            $table->foreign('user_id', 'hr_att_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('employee_id', 'hr_att_employee_fk')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('assignment_outlet_id', 'hr_att_assignment_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('checkin_outlet_id', 'hr_att_checkin_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('checkout_outlet_id', 'hr_att_checkout_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Raw HR attendance is an audit domain. Keep down non-destructive.
    }
};
