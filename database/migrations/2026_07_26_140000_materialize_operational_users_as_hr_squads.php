<?php

use App\Services\HrSquadUserWiringService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('HR_squads')) {
            return;
        }

        if (! Schema::hasColumn('users', 'nisj') || ! Schema::hasColumn('HR_squads', 'nisj') || ! Schema::hasColumn('HR_squads', 'user_id')) {
            return;
        }

        // Materialisasi non-destruktif dan idempotent:
        // - user operasional + NISJ => Data Squad Active/Inactive sesuai is_active
        // - Stakeholder/Observer atau user tanpa NISJ tetap Non-Squad
        // - record Squad existing tidak dioverwrite; hanya wiring NISJ/user_id diperbaiki
        app(HrSquadUserWiringService::class)->reconcileOperationalUsers(100000);
    }

    public function down(): void
    {
        // Tidak dibalik karena migrasi hanya melengkapi relasi bisnis yang seharusnya ada.
    }
};
