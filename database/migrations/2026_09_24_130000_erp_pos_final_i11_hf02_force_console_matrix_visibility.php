<?php

use App\Services\Console\ConsoleCanonicalAccessRecoveryService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ConsoleCanonicalAccessRecoveryService::class)->repair();
    }

    public function down(): void
    {
        // Intentionally non-destructive: rollback must not hide Console again.
    }
};
