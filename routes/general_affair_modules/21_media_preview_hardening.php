<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairMediaController;
use Illuminate\Support\Facades\Route;

// I21 media gateway is intentionally capability-token based and does not require
// an Authorization header so <img> / <a target="_blank"> can render normally.
// Tokens are HMAC-protected and only resolve files under general-affair/ on the
// public disk, preventing this route from becoming an arbitrary storage browser.
Route::get('api/v1/general-affair/media/{token}', GeneralAffairMediaController::class)
    ->middleware(['api', 'throttle:180,1'])
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('general-affair.i21.media');
