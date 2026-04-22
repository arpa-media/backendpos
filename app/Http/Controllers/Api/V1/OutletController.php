<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outlet\UpdateOutletRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Outlet\OutletResource;
use App\Http\Resources\Api\V1\Outlet\PosLoginOutletOptionResource;
use App\Models\Outlet;
use App\Support\Auth\UserAuthContextResolver;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OutletController extends Controller
{
    public function __construct(private readonly UserAuthContextResolver $resolver)
    {
    }

    public function index(Request $request)
    {
        $query = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        $user = $request->user();
        if ($user) {
            $ctx = $this->resolver->resolve($user);
            $isLocked = (bool) ($ctx['scope_locked'] ?? false);
            $resolvedOutletId = $ctx['resolved_outlet_id'] ?? null;

            if ($isLocked && $resolvedOutletId) {
                $query->whereKey($resolvedOutletId);
            }
        }

        $items = $query->get();

        return ApiResponse::ok([
            'items' => OutletResource::collection($items),
        ], 'OK');
    }

    public function posLoginOptions(Request $request)
    {
        $cacheKey = 'pos:login-options:v3';
        $staleKey = 'pos:login-options:stale:v3';

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return ApiResponse::ok([
                'items' => $cached,
            ], 'OK')->withHeaders([
                'X-POS-Outlets-Cache' => 'hit',
                'X-POS-Outlets-Count' => (string) count($cached),
                'X-POS-Outlets-Source' => 'cache',
            ]);
        }

        $startedAt = microtime(true);

        try {
            $items = $this->buildPosLoginOutletOptions();
            $payload = PosLoginOutletOptionResource::collection($items)->resolve();

            Cache::put($cacheKey, $payload, now()->addMinutes(5));
            Cache::put($staleKey, $payload, now()->addDay());

            return ApiResponse::ok([
                'items' => $payload,
            ], 'OK')->withHeaders([
                'X-POS-Outlets-Cache' => 'miss',
                'X-POS-Outlets-Count' => (string) count($payload),
                'X-POS-Outlets-Source' => 'fresh',
                'X-POS-Outlets-Gen-Ms' => (string) ((int) round((microtime(true) - $startedAt) * 1000)),
            ]);
        } catch (Throwable $exception) {
            $stale = Cache::get($staleKey);
            if (is_array($stale) && $stale !== []) {
                Log::warning('POS outlets served from stale cache fallback', [
                    'path' => $request->path(),
                    'error' => $exception->getMessage(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);

                return ApiResponse::ok([
                    'items' => $stale,
                ], 'OK')->withHeaders([
                    'X-POS-Outlets-Cache' => 'stale',
                    'X-POS-Outlets-Count' => (string) count($stale),
                    'X-POS-Outlets-Source' => 'stale-fallback',
                ]);
            }

            throw $exception;
        }
    }

    private function buildPosLoginOutletOptions()
    {
        $columns = [
            'id',
            'code',
            'name',
            'type',
            'timezone',
        ];

        if (Schema::hasColumn('outlets', 'is_active')) {
            $columns[] = 'is_active';
        }

        $baseQuery = Outlet::query()
            ->select($columns)
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        if (Schema::hasColumn('outlets', 'is_active')) {
            $baseQuery->where(function ($query) {
                $query->whereNull('is_active')->orWhere('is_active', true);
            });
        }

        $items = $baseQuery->get();

        if ($items->isEmpty()) {
            $fallback = Outlet::query()->select($columns)->orderBy('name');

            if (Schema::hasColumn('outlets', 'code')) {
                $fallback->whereNotNull('code');
            }

            $items = $fallback->get()->filter(function ($outlet) {
                $type = strtolower((string) ($outlet->type ?? 'outlet'));

                return $type === '' || $type === 'outlet';
            })->values();
        }

        return $items;
    }

    public function show(Request $request)
    {
        $outletId = OutletScope::id($request);

        if (! $outletId) {
            return ApiResponse::error(
                message: 'Outlet scope is required',
                errorCode: 'OUTLET_SCOPE_REQUIRED',
                status: 422
            );
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new OutletResource($outlet), 'OK');
    }

    public function update(UpdateOutletRequest $request)
    {
        $outletId = OutletScope::id($request);

        if (! $outletId) {
            return ApiResponse::error(
                message: 'Outlet scope is required',
                errorCode: 'OUTLET_SCOPE_REQUIRED',
                status: 422
            );
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet not found', 'NOT_FOUND', 404);
        }

        $outlet->fill($request->validated());
        $outlet->save();

        return ApiResponse::ok(new OutletResource($outlet->fresh()), 'Outlet updated');
    }
}
