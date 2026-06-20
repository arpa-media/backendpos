<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('HR_squads', 'user_id')) {
            Schema::table('HR_squads', function (Blueprint $table) {
                $table->ulid('user_id')->nullable()->after('id');
                $table->unique('user_id', 'hr_squads_user_id_unique');
                $table->foreign('user_id', 'hr_squads_user_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        $this->backfillExistingUserLinks();
        $this->normalizePersonalOptions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasColumn('HR_squads', 'user_id')) {
            return;
        }

        Schema::table('HR_squads', function (Blueprint $table) {
            try {
                $table->dropForeign('hr_squads_user_id_foreign');
            } catch (Throwable) {
            }

            try {
                $table->dropUnique('hr_squads_user_id_unique');
            } catch (Throwable) {
            }

            $table->dropColumn('user_id');
        });
    }

    private function backfillExistingUserLinks(): void
    {
        $userColumns = array_values(array_filter(
            ['id', 'nisj', 'username', 'email'],
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($userColumns === ['id']) {
            return;
        }

        DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereNull('user_id')
            ->orderBy('id')
            ->chunkById(200, function ($squads) use ($userColumns) {
                foreach ($squads as $squad) {
                    $identities = [];
                    foreach (['nisj', 'username', 'email'] as $column) {
                        if (! property_exists($squad, $column)) {
                            continue;
                        }

                        $value = mb_strtolower(trim((string) $squad->{$column}));
                        if ($value !== '') {
                            $identities[$column] = $value;
                        }
                    }

                    if ($identities === []) {
                        continue;
                    }

                    $user = DB::table('users')
                        ->select($userColumns)
                        ->where(function ($query) use ($identities) {
                            foreach ($identities as $column => $value) {
                                if (Schema::hasColumn('users', $column)) {
                                    $query->orWhereRaw("LOWER(TRIM(`{$column}`)) = ?", [$value]);
                                }
                            }
                        })
                        ->first();

                    if (! $user) {
                        continue;
                    }

                    $alreadyLinked = DB::table('HR_squads')
                        ->where('user_id', (string) $user->id)
                        ->where('id', '<>', $squad->id)
                        ->exists();

                    if (! $alreadyLinked) {
                        DB::table('HR_squads')
                            ->where('id', $squad->id)
                            ->whereNull('user_id')
                            ->update(['user_id' => (string) $user->id]);
                    }
                }
            });
    }

    private function normalizePersonalOptions(): void
    {
        if (Schema::hasColumn('HR_squads', 'gender')) {
            DB::table('HR_squads')
                ->whereIn(DB::raw('LOWER(TRIM(gender))'), ['l', 'lk', 'laki', 'laki-laki', 'laki laki', 'male', 'm', 'man', 'pria'])
                ->update(['gender' => 'Laki-Laki']);

            DB::table('HR_squads')
                ->whereIn(DB::raw('LOWER(TRIM(gender))'), ['p', 'pr', 'perempuan', 'female', 'f', 'woman', 'wanita'])
                ->update(['gender' => 'Perempuan']);
        }
    }
};
