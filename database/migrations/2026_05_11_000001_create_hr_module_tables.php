<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('HR_master_data')) {
            Schema::create('HR_master_data', function (Blueprint $table) {
                $table->id();
                $table->string('type', 50)->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['type', 'name', 'deleted_at'], 'HR_master_unique_type_name_deleted');
            });
        }

        if (!Schema::hasTable('HR_salary_tiers')) {
            Schema::create('HR_salary_tiers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                $table->decimal('basic_salary', 15, 2)->default(0);
                $table->decimal('daily_salary', 15, 2)->default(0);
                $table->decimal('minute_deduction', 15, 2)->default(0);
                $table->decimal('hourly_overtime', 15, 2)->default(0);
                $table->decimal('bonus', 15, 2)->default(0);
                $table->decimal('family_allowance', 15, 2)->default(0);
                $table->decimal('position_allowance', 15, 2)->default(0);
                $table->decimal('cashbon', 15, 2)->default(0);
                $table->decimal('other', 15, 2)->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('HR_squads')) {
            Schema::create('HR_squads', function (Blueprint $table) {
                $table->id();
                $table->string('full_name', 180)->index();
                $table->string('nickname', 80)->nullable();
                $table->string('nik', 60)->nullable()->index();
                $table->text('address')->nullable();
                $table->string('birth_place', 100)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('gender', 30)->nullable();
                $table->string('religion', 60)->nullable();
                $table->string('education', 80)->nullable();
                $table->string('marital_status', 80)->nullable();
                $table->unsignedSmallInteger('children_count')->default(0);
                $table->string('whatsapp', 40)->nullable();
                $table->string('email', 180)->nullable()->unique();
                $table->string('status', 20)->default('active')->index();
                $table->string('nisj', 80)->nullable()->unique();
                $table->string('employee_type', 80)->nullable();
                $table->string('bank_account', 100)->nullable();
                $table->string('bpjs_number', 100)->nullable();
                $table->string('bpjstk_number', 100)->nullable();
                $table->string('faskes', 150)->nullable();
                $table->boolean('ppi_status')->default(false);
                $table->string('photo_path')->nullable();
                $table->string('contract_type', 80)->nullable();
                $table->date('contract_start_date')->nullable();
                $table->date('contract_end_date')->nullable();
                $table->string('assignment', 180)->nullable()->index();
                $table->string('chamber_name', 150)->nullable()->index();
                $table->string('division_name', 150)->nullable()->index();
                $table->string('position_name', 150)->nullable()->index();
                $table->unsignedBigInteger('salary_tier_id')->nullable()->index();
                $table->string('salary_tier_name', 150)->nullable();
                $table->decimal('basic_salary', 15, 2)->default(0);
                $table->decimal('daily_salary', 15, 2)->default(0);
                $table->decimal('minute_deduction', 15, 2)->default(0);
                $table->decimal('hourly_overtime', 15, 2)->default(0);
                $table->decimal('bonus', 15, 2)->default(0);
                $table->decimal('family_allowance', 15, 2)->default(0);
                $table->decimal('position_allowance', 15, 2)->default(0);
                $table->decimal('cashbon', 15, 2)->default(0);
                $table->decimal('other', 15, 2)->default(0);
                $table->string('username', 100)->nullable()->unique();
                $table->string('password', 190)->nullable();
                $table->string('role_name', 150)->nullable()->index();
                $table->string('user_chamber_name', 150)->nullable();
                $table->string('access_role', 150)->nullable();
                $table->string('access_level', 150)->nullable();
                $table->unsignedSmallInteger('leave_quota')->default(3);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->seedMasters();
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_squads');
        Schema::dropIfExists('HR_salary_tiers');
        Schema::dropIfExists('HR_master_data');
    }

    private function seedMasters(): void
    {
        $masters = [
            'chamber' => ['EXECUTIVE', 'BRAND', 'FINANCE', 'HUMAN RESOURCE', 'OPERATION', 'WAREHOUSE', 'OUTLET', 'JAYA FUTURE LEAGUE', 'GENERAL AFFAIR'],
            'role' => ['DIREKSI', 'MANAGEMENT', 'SQUAD', 'ADMIN', 'STAKEHOLDER', 'OBSERVER'],
            'division' => ['SPV', 'BARISTA', 'KASIR', 'KITCHEN', 'PIZZA', 'SERVER', 'STEWARD'],
            'position' => ['CBO', 'CREATIVE DIRECTOR', 'CUSTOMER RELATIONSHIP', 'STAFF BRAND', 'AREA MANAGER BALI', 'AREA MANAGER MALANG', 'CDP', 'STAFF FINANCE', 'CPPO', 'ADMIN WAREHOUSE', 'HEAD BAR', 'HEAD KITCHEN', 'OPERATING OFFICER', 'HUMAN RESOURCES MANAGER', 'AREA MANAGER', 'STAFF BAR EXECUTIVE', 'SECRETERY CHAMBERS', 'SQUAD JFL', 'SQUAD BARISTA', 'SQUAD KITCHEN', 'SQUAD PIZZA', 'SQUAD KASIR', 'SQUAD PRODUKSI', 'SQUAD SERVER', 'SQUAD STEWARD', 'SQUAD WAREHOUSE', 'SQUAD WAREHOUSE BALI', 'SQUAD WAREHOUSE BANJARMASIN', 'SQUAD WAREHOUSE BANDUNG', 'TRAINER OPERATION'],
            'company' => ['BKJB', 'MDMF', 'APB'],
        ];

        $now = now();
        foreach ($masters as $type => $names) {
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($names as $name) {
                $exists = DB::table('HR_master_data')->where('type', $type)->where('name', $name)->whereNull('deleted_at')->exists();
                if (!$exists) {
                    DB::table('HR_master_data')->insert([
                        'type' => $type,
                        'name' => $name,
                        'description' => null,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
