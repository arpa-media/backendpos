<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ga_tickets';
    private const INDEX = 'ga_ticket_active_date_i12_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasIndex(self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['deleted_at', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->hasIndex(self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function hasIndex(string $name): bool
    {
        try {
            return count(DB::select('SHOW INDEX FROM `'.self::TABLE.'` WHERE `Key_name` = ?', [$name])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
