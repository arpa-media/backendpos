<?php

use App\Support\Auth\ManualAssignmentOverrideApplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['outlets', 'users', 'employees', 'assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        app(ManualAssignmentOverrideApplier::class)->sync([
            'manage_transaction' => false,
        ]);
    }

    public function down(): void
    {
        // Data patch is intentionally non-destructive.
    }
};
