<?php

namespace App\Services\HumanResource;

use App\Models\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class HrGeofenceService
{
    public function eligibleOutlets(): Collection
    {
        return Outlet::query()
            ->select(['id', 'code', 'name', 'type', 'address', 'timezone', 'latitude', 'longitude', 'radius_m'])
            ->when(Schema::hasColumn('outlets', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('radius_m')
            ->where('radius_m', '>', 0)
            ->orderBy('name')
            ->get();
    }

    public function resolve(float $latitude, float $longitude): array
    {
        $nearest = null;

        foreach ($this->eligibleOutlets() as $outlet) {
            $candidate = $this->resolveOutlet($outlet, $latitude, $longitude);
            if ($candidate['outlet'] && ($nearest === null || $candidate['distance_m'] < $nearest['distance_m'])) {
                $nearest = $candidate;
            }
        }

        return $nearest ?? $this->emptyResult();
    }

    /**
     * Resolve geofence against one explicit assigned outlet. Used for operational
     * squad assigned to outlet type=outlet so another physically-nearer outlet
     * can never be treated as an in-radius attendance target.
     */
    public function resolveAssignedOutlet(?Outlet $outlet, float $latitude, float $longitude): array
    {
        if (! $outlet || strtolower((string) $outlet->type) !== 'outlet') {
            return $this->emptyResult();
        }

        if ($outlet->latitude === null || $outlet->longitude === null || (int) $outlet->radius_m <= 0) {
            return $this->emptyResult();
        }

        return $this->resolveOutlet($outlet, $latitude, $longitude);
    }

    private function resolveOutlet(Outlet $outlet, float $latitude, float $longitude): array
    {
        $distance = $this->haversineMeters(
            $latitude,
            $longitude,
            (float) $outlet->latitude,
            (float) $outlet->longitude
        );
        $radius = max(1, (int) $outlet->radius_m);

        return [
            'outlet' => $outlet,
            'distance_m' => (int) round($distance),
            'radius_m' => $radius,
            'radius_delta_m' => (int) round($distance - $radius),
            'inside_radius' => $distance <= $radius,
        ];
    }

    private function emptyResult(): array
    {
        return [
            'outlet' => null,
            'distance_m' => null,
            'radius_m' => null,
            'radius_delta_m' => null,
            'inside_radius' => false,
        ];
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));

        return $earthRadius * $c;
    }
}
