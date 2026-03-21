<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $modelMorphKey = $columnNames['model_morph_key'] ?? 'model_id';

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not loaded.');
        }

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        */
        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */
        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
            $table->bigIncrements('id');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key']);
            }

            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            if ($teams) {
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Model Has Permissions (ULID FIX)
        |--------------------------------------------------------------------------
        */
        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use (
            $tableNames,
            $columnNames,
            $pivotPermission,
            $modelMorphKey,
            $teams
        ) {
            $table->unsignedBigInteger($pivotPermission);
            $table->string('model_type');
            $table->ulid($modelMorphKey);

            $table->index([$modelMorphKey, 'model_type']);

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key']);

                $table->primary([
                    $columnNames['team_foreign_key'],
                    $pivotPermission,
                    $modelMorphKey,
                    'model_type'
                ]);
            } else {
                $table->primary([
                    $pivotPermission,
                    $modelMorphKey,
                    'model_type'
                ]);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Model Has Roles (ULID FIX)
        |--------------------------------------------------------------------------
        */
        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use (
            $tableNames,
            $columnNames,
            $pivotRole,
            $modelMorphKey,
            $teams
        ) {
            $table->unsignedBigInteger($pivotRole);
            $table->string('model_type');
            $table->ulid($modelMorphKey);

            $table->index([$modelMorphKey, 'model_type']);

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key']);

                $table->primary([
                    $columnNames['team_foreign_key'],
                    $pivotRole,
                    $modelMorphKey,
                    'model_type'
                ]);
            } else {
                $table->primary([
                    $pivotRole,
                    $modelMorphKey,
                    'model_type'
                ]);
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Role Has Permissions
        |--------------------------------------------------------------------------
        */
        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use (
            $tableNames,
            $pivotRole,
            $pivotPermission
        ) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');

            $table->primary([$pivotPermission, $pivotRole]);
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default'
                ? config('permission.cache.store')
                : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
