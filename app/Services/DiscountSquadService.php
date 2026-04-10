<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\DiscountSquadUsage;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DiscountSquadService
{
    public function currentPeriodKey(?string $timezone = null): string
    {
        return now($this->normalizeTimezone($timezone))->format('ymd');
    }

    public function periodKeyForMoment($moment, ?string $timezone = null): string
    {
        $tz = $this->normalizeTimezone($timezone);

        try {
            if ($moment instanceof \DateTimeInterface) {
                return Carbon::instance($moment)->setTimezone($tz)->format('ymd');
            }

            if (is_string($moment) && trim($moment) !== '') {
                return Carbon::parse($moment)->setTimezone($tz)->format('ymd');
            }
        } catch (\Throwable) {
            // fall through to current period
        }

        return $this->currentPeriodKey($tz);
    }

    public function normalizeTimezone(?string $timezone = null): string
    {
        $value = trim((string) ($timezone ?: config('app.timezone', 'Asia/Jakarta')));

        return $value !== '' ? $value : 'Asia/Jakarta';
    }

    public function resolveOutletTimezone(?string $outletId): string
    {
        $outletId = trim((string) $outletId);
        if ($outletId === '') {
            return $this->normalizeTimezone();
        }

        $timezone = Outlet::query()->whereKey($outletId)->value('timezone');

        return $this->normalizeTimezone(is_string($timezone) ? $timezone : null);
    }

    public function normalizeNisj(?string $nisj): string
    {
        return trim((string) $nisj);
    }

    public function findUserByNisj(?string $nisj): ?User
    {
        $normalized = $this->normalizeNisj($nisj);
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->where('nisj', $normalized)
            ->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', true);
            })
            ->first();
    }

    public function isAvailableForNisj(?string $nisj, ?string $periodKey = null): bool
    {
        $normalized = $this->normalizeNisj($nisj);
        if ($normalized === '') {
            return false;
        }

        return ! DiscountSquadUsage::query()
            ->where('nisj', $normalized)
            ->where('period_key', $periodKey ?: $this->currentPeriodKey())
            ->exists();
    }

    public function quotaPayloadForUser(?User $user, ?string $periodKey = null): array
    {
        $period = $periodKey ?: $this->currentPeriodKey();
        $nisj = $this->normalizeNisj($user?->nisj);
        $available = $nisj !== '' && $this->isAvailableForNisj($nisj, $period);

        return [
            'period_key' => $period,
            'quota_total' => 1,
            'quota_used' => $available ? 0 : 1,
            'quota_remaining' => $available ? 1 : 0,
            'quota_available' => $available,
            'quota_message' => $available
                ? 'Jatah discount squad masih tersedia untuk hari ini.'
                : 'Jatah discount squad sudah terpakai untuk hari ini.',
        ];
    }

    public function searchUsers(string $q, int $limit = 10, ?string $timezone = null): Collection
    {
        $term = trim($q);
        if ($term === '') {
            return collect();
        }

        $limit = max(1, min(20, $limit));
        $period = $this->currentPeriodKey($timezone);

        return User::query()
            ->whereNotNull('nisj')
            ->where('nisj', '!=', '')
            ->where(function ($outer) use ($term) {
                $outer->where('nisj', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            })
            ->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', true);
            })
            ->orderByRaw('CASE WHEN nisj = ? THEN 0 ELSE 1 END', [$term])
            ->orderByRaw('CASE WHEN nisj LIKE ? THEN 0 ELSE 1 END', [$term.'%'])
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$term.'%'])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(function (User $user) use ($period) {
                return array_merge([
                    'id' => (string) $user->id,
                    'name' => (string) ($user->name ?? ''),
                    'nisj' => (string) ($user->nisj ?? ''),
                    'username' => (string) ($user->username ?? ''),
                    'email' => (string) ($user->email ?? ''),
                ], $this->quotaPayloadForUser($user, $period));
            })
            ->values();
    }

    public function registerUsage(Discount $discount, Sale $sale, User $user): DiscountSquadUsage
    {
        $nisj = $this->normalizeNisj($user->nisj);
        $timezone = $this->resolveOutletTimezone((string) $sale->outlet_id);
        $period = $this->currentPeriodKey($timezone);

        if ($nisj === '') {
            throw ValidationException::withMessages([
                'discount_squad_nisj' => ['NISJ squad tidak valid.'],
            ]);
        }

        if (! $this->isAvailableForNisj($nisj, $period)) {
            throw ValidationException::withMessages([
                'discount_squad_nisj' => ['Jatah discount squad untuk NISJ tersebut sudah terpakai hari ini.'],
            ]);
        }

        try {
            return DiscountSquadUsage::query()->create([
                'discount_id' => (string) $discount->id,
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'user_id' => (string) $user->id,
                'nisj' => $nisj,
                'user_name' => (string) ($user->name ?? ''),
                'period_key' => $period,
                'used_at' => now(),
            ]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'discount_squad_nisj' => ['Jatah discount squad untuk NISJ tersebut sudah terpakai hari ini.'],
            ]);
        }
    }
}
