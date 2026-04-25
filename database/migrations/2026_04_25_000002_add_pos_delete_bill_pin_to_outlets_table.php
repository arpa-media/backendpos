<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outlets', 'pos_delete_bill_pin')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->string('pos_delete_bill_pin', 12)->default('0341')->after('passwordwifi');
            });
        }

        DB::table('outlets')
            ->whereNull('pos_delete_bill_pin')
            ->orWhere('pos_delete_bill_pin', '')
            ->update(['pos_delete_bill_pin' => '0341']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('outlets', 'pos_delete_bill_pin')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->dropColumn('pos_delete_bill_pin');
            });
        }
    }
};
