<?php

use App\Http\Controllers\Api\V1\Operational\LoggedUsersV15Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Included from routes/api.php inside auth:sanctum, outlet_scope, outlet_timezone.
Route::get('/auth/pos-session-watch', static function (Request $request) {
    $name = strtolower((string) $request->user()?->currentAccessToken()?->name);
    if ($name !== 'pos' && ! str_starts_with($name, 'pos:')) {
        return ApiResponse::error('POS session required.', 'POS_SESSION_REQUIRED', 403);
    }
    return ApiResponse::ok(['valid' => true], 'POS interactive session valid');
});
Route::get('/operational/logged-users', [LoggedUsersV15Controller::class, 'index'])
    ->middleware('permission_or_snapshot:operational.logged_users.view');
Route::get('/operational/logged-users/events', [LoggedUsersV15Controller::class, 'events'])
    ->middleware('permission_or_snapshot:operational.logged_users.view');
Route::delete('/operational/logged-users/{id}', [LoggedUsersV15Controller::class, 'kick'])
    ->whereNumber('id')->middleware('permission_or_snapshot:operational.logged_users.delete');
