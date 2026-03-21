<?php

namespace App\Support\HrSync;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HrAuthContextClient
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function fetch(array $filters = []): array
    {
        $baseUrl = rtrim((string) config('services.hr_sync.base_url'), '/');
        $endpoint = (string) config('services.hr_sync.endpoint', '/api/internal/pos/auth-context');
        $token = (string) config('services.hr_sync.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('HR sync configuration is incomplete. Please set HR_SYNC_BASE_URL and HR_SYNC_TOKEN.');
        }

        $response = Http::timeout((int) config('services.hr_sync.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'X-POS-SYNC-TOKEN' => $token,
            ])
            ->get($baseUrl.$endpoint, array_filter([
                'since' => Arr::get($filters, 'since'),
                'active_only' => Arr::get($filters, 'active_only', true),
            ], fn ($value) => $value !== null && $value !== ''));

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Failed to fetch HR auth context snapshot [%s]: %s',
                $response->status(),
                $response->body()
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid HR auth context response payload.');
        }

        return $payload;
    }
}
