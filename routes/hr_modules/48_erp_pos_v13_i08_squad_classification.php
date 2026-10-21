<?php

use App\Http\Controllers\Api\V1\HumanResource\HrV13I08SquadClassificationController;
use Illuminate\Support\Facades\Route;

// Included within /api/v1/human-resource + auth:sanctum + outlet_scope + permission:auth.me.
// Uses the existing Data Squad / Access Matrix "update" permission, no new menu.
Route::put('/v13-i08/squads/{id}/classification', [HrV13I08SquadClassificationController::class, 'update'])
    ->whereNumber('id')
    ->middleware('permission_or_snapshot:hr.squad.update');
