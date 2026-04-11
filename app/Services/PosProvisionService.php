<?php

namespace App\Services;

use App\Http\Resources\Api\V1\Auth\MeResource;
use App\Models\Assignment;
use App\Models\Discount;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use App\Support\SalesChannels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PosProvisionService
{
    public function __construct(
        private readonly UserAuthContextResolver $resolver,
        private readonly TaxService $taxes,
        private readonly UserManagementService $userManagement,
        private readonly ReportPortalAccessService $reportPortalAccess,
    ) {
    }

    public function buildForUser(User $user): array
    {
        $ctx = $this->resolver->resolve($user);
        $outletId = (string) ($ctx['resolved_outlet_id'] ?? '');
        $outletCode = strtoupper(trim((string) ($ctx['resolved_outlet_code'] ?? '')));

        if ($outletId === '' || $outletCode === '') {
            return [
                'ready' => false,
                'reason' => 'OUTLET_CONTEXT_MISSING',
                'outlet' => null,
                'users' => [],
                'channels' => $this->supportedProvisionChannels(),
                'default_channel' => SalesChannels::DINE_IN,
                'manifest' => null,
                'manifests' => [],
                'snapshot' => null,
                'snapshots' => [],
            ];
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return [
                'ready' => false,
                'reason' => 'OUTLET_NOT_FOUND',
                'outlet' => null,
                'users' => [],
                'channels' => $this->supportedProvisionChannels(),
                'default_channel' => SalesChannels::DINE_IN,
                'manifest' => null,
                'manifests' => [],
                'snapshot' => null,
                'snapshots' => [],
            ];
        }

        $channels = $this->supportedProvisionChannels();
        $defaultChannel = $channels[0] ?? SalesChannels::DINE_IN;
        $users = $this->buildProvisionUsers($outletId, $outletCode);

        $snapshots = [];
        $manifests = [];
        foreach ($channels as $channel) {
            $snapshot = $this->buildTransactionSnapshot($outlet, $channel);
            $snapshots[$channel] = $snapshot;
            $manifests[$channel] = $this->buildMasterManifestFromSnapshot($snapshot, $channel);
        }

        $aggregateManifest = $this->buildProvisionManifestFromSnapshots($outletCode, $snapshots);
        $latestMasterCachedAt = collect($snapshots)
            ->map(fn (array $snapshot) => $snapshot['cached_at'] ?? null)
            ->filter()
            ->sort()
            ->last();

        return [
            'ready' => true,
            'reason' => null,
            'outlet' => [
                'id' => (string) $outlet->id,
                'code' => $outletCode,
                'name' => (string) ($outlet->name ?? $outletCode),
                'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
                'type' => (string) ($outlet->type ?? 'outlet'),
            ],
            'outlet_id' => (string) $outlet->id,
            'outlet_code' => $outletCode,
            'outlet_name' => (string) ($outlet->name ?? $outletCode),
            'outlet_timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            'channel' => 'PROVISION',
            'channels' => $channels,
            'default_channel' => $defaultChannel,
            'users' => $users,
            'snapshot' => $snapshots[$defaultChannel] ?? null,
            'snapshots' => $snapshots,
            'manifest' => $aggregateManifest,
            'manifests' => $manifests,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'contract' => 'pos-device-provision-package',
                'version' => 3,
                'users_count' => count($users),
                'channels_count' => count($channels),
                'default_channel' => $defaultChannel,
                'master_cached_at' => $latestMasterCachedAt,
                'snapshot_contract' => 'pos-bootstrap-snapshot',
                'manifest_contract' => 'pos-master-manifest',
            ],
        ];
    }

    public function buildOfflineSeedForUser(User $user, ?string $forcedOutletCode = null): ?array
    {
        $resolvedUser = $user->fresh()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $ctx = $this->resolver->resolve($resolvedUser);
        $outletCode = strtoupper(trim((string) ($forcedOutletCode ?: ($ctx['resolved_outlet_code'] ?? $resolvedUser->outlet?->code ?? ''))));

        if ($outletCode === '') {
            return null;
        }

        $roleName = optional($resolvedUser->roles->first())->name ?: 'cashier';
        $seed = $this->buildProvisionUserPayload($resolvedUser, $outletCode, (string) $roleName);
        if (! $seed) {
            return null;
        }

        $seed['seed_version'] = 1;
        $seed['seeded_at'] = now()->toIso8601String();
        $seed['seed_source'] = 'login-refresh';

        return $seed;
    }

    protected function supportedProvisionChannels(): array
    {
        return [
            SalesChannels::DINE_IN,
            SalesChannels::TAKEAWAY,
            SalesChannels::DELIVERY,
        ];
    }

    protected function buildProvisionUsers(string $outletId, string $outletCode): array
    {
        $this->userManagement->ensureMasters();

        $assignments = Assignment::query()
            ->with(['employee.user.roles', 'employee.user.permissions', 'employee.user.employee.assignment.outlet', 'employee.user.outlet', 'employee.user.reportOutletAssignments.outlet'])
            ->where('outlet_id', $outletId)
            ->when(DB::getSchemaBuilder()->hasColumn('assignments', 'status'), function ($query) {
                $query->where(function ($sub) {
                    $sub->whereNull('status')->orWhere('status', 'active');
                });
            })
            ->orderByDesc('is_primary')
            ->orderBy('updated_at')
            ->get();

        if ($assignments->isEmpty()) {
            $assignments = Assignment::query()
                ->with(['employee.user.roles', 'employee.user.permissions', 'employee.user.employee.assignment.outlet', 'employee.user.outlet', 'employee.user.reportOutletAssignments.outlet'])
                ->whereHas('employee.user', function ($query) use ($outletId) {
                    $query->where('outlet_id', $outletId);
                })
                ->orderByDesc('is_primary')
                ->orderBy('updated_at')
                ->get();
        }

        return $assignments
            ->map(function (Assignment $assignment) use ($outletCode) {
                try {
                    $employee = $assignment->employee;
                    $user = $employee?->user;
                    if (! $employee || ! $user) {
                        return null;
                    }

                    if (($user->is_active ?? true) === false) {
                        return null;
                    }

                    $nisj = trim((string) ($employee->nisj ?? $user->nisj ?? ''));
                    if ($nisj === '') {
                        return null;
                    }

                    $resolvedUser = $user->fresh()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
                    $roleName = optional($resolvedUser->roles->first())->name ?: (string) ($assignment->role_title ?: 'cashier');
                    $payload = $this->buildProvisionUserPayload($resolvedUser, $outletCode, (string) $roleName);
                    if (! $payload) {
                        return null;
                    }

                    $payload['assignment_role_title'] = (string) ($assignment->role_title ?? '');
                    $payload['updated_at'] = optional($assignment->updated_at)->toIso8601String() ?: now()->toIso8601String();

                    return $payload;
                } catch (\Throwable $exception) {
                    Log::warning('POS provision user skipped', [
                        'assignment_id' => $assignment->id,
                        'employee_id' => $assignment->employee_id,
                        'message' => $exception->getMessage(),
                    ]);
                    return null;
                }
            })
            ->filter()
            ->unique(fn (array $item) => $item['nisj'])
            ->sortBy(fn (array $item) => $item['nisj'])
            ->values()
            ->all();
    }

    protected function buildProvisionUserPayload(User $resolvedUser, string $outletCode, string $roleName = 'cashier'): ?array
    {
        $resolvedUser = $resolvedUser->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        if (($resolvedUser->is_active ?? true) === false) {
            return null;
        }

        $employee = $resolvedUser->employee;
        $nisj = trim((string) ($employee?->nisj ?? $resolvedUser->nisj ?? ''));
        if ($nisj === '') {
            return null;
        }

        $sessionSnapshot = $this->userManagement->currentSessionSnapshot($resolvedUser);
        $authContext = $this->resolver->resolve($resolvedUser);
        $userResource = new MeResource($resolvedUser);
        $userPayload = $userResource->toArray(request());

        return [
            'id' => (string) $resolvedUser->id,
            'user_id' => (string) $resolvedUser->id,
            'nisj' => $nisj,
            'username' => (string) ($resolvedUser->username ?? $nisj),
            'name' => trim((string) ($employee?->full_name ?? $resolvedUser->name ?? $nisj)),
            'outlet_code' => $outletCode,
            'role_name' => Str::upper(trim($roleName) !== '' ? $roleName : 'cashier'),
            'password_seeded' => filled($resolvedUser->password),
            'password_hash' => filled($resolvedUser->password) ? (string) $resolvedUser->password : null,
            'offline_auth_scheme' => 'bcrypt',
            'offline_grant' => true,
            'offline_session' => [
                'token' => null,
                'token_type' => 'Offline',
                'abilities' => $sessionSnapshot['permissions'] ?? [],
                'auth_context' => $authContext,
                'user' => $userPayload,
                'access' => $sessionSnapshot['access'] ?? ['portals' => [], 'menus' => []],
                'visible_backoffice_portals' => $sessionSnapshot['visible_backoffice_portals'] ?? [],
                'can_edit_user_management' => (bool) ($sessionSnapshot['can_edit_user_management'] ?? false),
                'report_access' => $sessionSnapshot['report_access'] ?? $this->reportPortalAccess->snapshot($resolvedUser),
                'permissions' => $sessionSnapshot['permissions'] ?? [],
            ],
            'updated_at' => optional($resolvedUser->updated_at)->toIso8601String() ?: now()->toIso8601String(),
        ];
    }

    protected function buildTransactionSnapshot(Outlet $outlet, string $channel = SalesChannels::DINE_IN): array
    {
        $outletId = (string) $outlet->id;
        $outletCode = strtoupper(trim((string) ($outlet->code ?? '')));
        $channel = strtoupper(trim($channel ?: SalesChannels::DINE_IN));
        $supportedChannels = $this->supportedProvisionChannels();

        $products = Product::query()
            ->where('is_active', true)
            ->whereHas('outlets', function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId)
                    ->where('outlet_product.is_active', true);
            })
            ->with([
                'category:id,name,slug,kind,updated_at',
                'variants' => function ($query) use ($outletId, $channel, $supportedChannels) {
                    $query->where('outlet_id', $outletId)
                        ->where('is_active', true)
                        ->whereHas('prices', function ($priceQuery) use ($outletId, $channel) {
                            $priceQuery->where('outlet_id', $outletId)
                                ->where('channel', $channel);
                        })
                        ->with(['prices' => function ($priceQuery) use ($outletId, $supportedChannels) {
                            $priceQuery->where('outlet_id', $outletId)
                                ->whereIn('channel', $supportedChannels)
                                ->orderBy('id');
                        }])
                        ->orderBy('id');
                },
            ])
            ->orderBy('name')
            ->get();

        $categoryMap = [];
        $productRows = [];
        foreach ($products as $product) {
            $category = $product->category;
            if ($category && $category->id) {
                $categoryMap[(string) $category->id] = [
                    'id' => (string) $category->id,
                    'name' => (string) ($category->name ?? ''),
                    'slug' => (string) ($category->slug ?? ''),
                    'kind' => (string) ($category->kind ?? 'OTHER'),
                    'updated_at' => optional($category->updated_at)->toIso8601String(),
                ];
            }

            $variants = [];
            foreach ($product->variants as $variant) {
                $prices = $variant->prices
                    ->map(fn ($price) => [
                        'id' => (string) $price->id,
                        'channel' => (string) ($price->channel ?? ''),
                        'price' => (int) ($price->price ?? 0),
                        'updated_at' => optional($price->updated_at)->toIso8601String(),
                    ])
                    ->values()
                    ->all();

                $variants[] = [
                    'id' => (string) $variant->id,
                    'product_id' => (string) $product->id,
                    'name' => (string) ($variant->name ?? ''),
                    'sku' => (string) ($variant->sku ?? ''),
                    'channel' => (string) ($variant->channel ?? ''),
                    'is_active' => (bool) ($variant->is_active ?? true),
                    'updated_at' => optional($variant->updated_at)->toIso8601String(),
                    'prices' => $prices,
                ];
            }

            $productRows[] = [
                'id' => (string) $product->id,
                'category_id' => $product->category_id ? (string) $product->category_id : null,
                'name' => (string) ($product->name ?? ''),
                'slug' => (string) ($product->slug ?? ''),
                'is_active' => true,
                'updated_at' => optional($product->updated_at)->toIso8601String(),
                'category' => $category && $category->id ? [
                    'id' => (string) $category->id,
                    'name' => (string) ($category->name ?? ''),
                    'slug' => (string) ($category->slug ?? ''),
                    'kind' => (string) ($category->kind ?? 'OTHER'),
                    'updated_at' => optional($category->updated_at)->toIso8601String(),
                ] : null,
                'variants' => $variants,
            ];
        }

        $categories = collect($categoryMap)
            ->sortBy(fn (array $row) => $row['name'] ?? $row['id'])
            ->values()
            ->all();

        $paymentMethods = PaymentMethod::query()
            ->where('is_active', true)
            ->whereHas('outlets', function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId)
                    ->where('outlet_payment_method.is_active', true);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'sort_order', 'updated_at'])
            ->map(fn (PaymentMethod $method) => [
                'id' => (string) $method->id,
                'name' => (string) ($method->name ?? ''),
                'type' => (string) ($method->type ?? ''),
                'sort_order' => (int) ($method->sort_order ?? 0),
                'updated_at' => optional($method->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $discountPackages = Discount::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->with(['products:id', 'customers:id'])
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'applies_to', 'discount_type', 'discount_value', 'updated_at'])
            ->map(fn (Discount $discount) => [
                'id' => (string) $discount->id,
                'code' => (string) ($discount->code ?? ''),
                'name' => (string) ($discount->name ?? ''),
                'applies_to' => (string) ($discount->applies_to ?? ''),
                'discount_type' => (string) ($discount->discount_type ?? ''),
                'discount_value' => (int) ($discount->discount_value ?? 0),
                'product_ids' => $discount->products
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values()
                    ->all(),
                'customer_ids' => $discount->customers
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values()
                    ->all(),
                'updated_at' => optional($discount->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $defaultTax = $this->taxes->getActiveDefaultForOutlet($outletId);
        $tax = $defaultTax ? [
            'id' => (string) $defaultTax->id,
            'name' => (string) ($defaultTax->name ?? 'Tax'),
            'percent' => (int) ($defaultTax->percent ?? 0),
            'updated_at' => optional($defaultTax->updated_at)->toIso8601String(),
        ] : null;

        return [
            'outlet' => [
                'id' => (string) $outlet->id,
                'code' => $outletCode,
                'name' => (string) ($outlet->name ?? $outletCode),
                'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
                'type' => (string) ($outlet->type ?? 'outlet'),
            ],
            'outlet_code' => $outletCode,
            'channel' => $channel,
            'categories' => $categories,
            'products' => $productRows,
            'paymentMethods' => $paymentMethods,
            'discountPackages' => $discountPackages,
            'tax' => $tax,
            'tax_percent' => (int) ($tax['percent'] ?? 0),
            'cached_at' => now()->toIso8601String(),
        ];
    }

    protected function buildMasterManifestFromSnapshot(array $snapshot, ?string $channel = null): array
    {
        $outletCode = (string) ($snapshot['outlet_code'] ?? '');
        $manifestChannel = strtoupper(trim((string) ($channel ?: ($snapshot['channel'] ?? SalesChannels::DINE_IN))));
        $domainRows = $this->manifestRowsFromSnapshot($snapshot);

        return $this->buildManifestFromDomainRows(
            $outletCode,
            $manifestChannel,
            $domainRows,
            [$manifestChannel],
            (string) ($snapshot['cached_at'] ?? now()->toIso8601String())
        );
    }

    protected function buildProvisionManifestFromSnapshots(string $outletCode, array $snapshots): array
    {
        $mergedDomainRows = [
            'categories' => [],
            'products' => [],
            'variants' => [],
            'variant_prices' => [],
            'payment_methods' => [],
            'discounts' => [],
            'tax' => [],
        ];

        $channels = [];
        $generatedAt = null;

        foreach ($snapshots as $channel => $snapshot) {
            if (! is_array($snapshot) || empty($snapshot)) {
                continue;
            }

            $normalizedChannel = strtoupper(trim((string) ($channel ?: ($snapshot['channel'] ?? ''))));
            if ($normalizedChannel === '') {
                continue;
            }

            $channels[] = $normalizedChannel;
            $generatedAt = max((string) ($generatedAt ?: ''), (string) ($snapshot['cached_at'] ?? '')) ?: $generatedAt;
            $rows = $this->manifestRowsFromSnapshot($snapshot);
            foreach ($mergedDomainRows as $domain => $_) {
                $mergedDomainRows[$domain] = array_merge($mergedDomainRows[$domain], $rows[$domain] ?? []);
            }
        }

        foreach (array_keys($mergedDomainRows) as $domain) {
            $mergedDomainRows[$domain] = $this->uniqueDomainRows($mergedDomainRows[$domain]);
        }

        return $this->buildManifestFromDomainRows(
            $outletCode,
            'PROVISION',
            $mergedDomainRows,
            array_values(array_unique($channels)),
            $generatedAt ?: now()->toIso8601String()
        );
    }

    protected function manifestRowsFromSnapshot(array $snapshot): array
    {
        $products = collect($snapshot['products'] ?? [])->filter(fn ($item) => ! empty($item['id']))->values();
        $activeCategoryIds = $products
            ->pluck('category_id')
            ->filter()
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        $categoryRows = collect($snapshot['categories'] ?? [])
            ->filter(fn ($item) => ! empty($item['id']) && $activeCategoryIds->contains((string) $item['id']))
            ->map(fn ($item) => [
                'id' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? ''),
                'slug' => (string) ($item['slug'] ?? ''),
                'kind' => (string) ($item['kind'] ?? 'OTHER'),
                'updated_at' => $item['updated_at'] ?? null,
            ])
            ->values()
            ->all();

        $productRows = $products
            ->map(fn ($item) => [
                'id' => (string) $item['id'],
                'category_id' => ! empty($item['category_id']) ? (string) $item['category_id'] : null,
                'name' => (string) ($item['name'] ?? ''),
                'slug' => (string) ($item['slug'] ?? ''),
                'updated_at' => $item['updated_at'] ?? null,
            ])
            ->values()
            ->all();

        $variantRows = [];
        $variantPriceRows = [];
        foreach ($products as $product) {
            foreach (($product['variants'] ?? []) as $variant) {
                if (empty($variant['id']) || ($variant['is_active'] ?? true) === false) {
                    continue;
                }
                $variantRows[] = [
                    'id' => (string) $variant['id'],
                    'product_id' => ! empty($variant['product_id']) ? (string) $variant['product_id'] : (string) $product['id'],
                    'name' => (string) ($variant['name'] ?? ''),
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'channel' => (string) ($variant['channel'] ?? ''),
                    'updated_at' => $variant['updated_at'] ?? null,
                ];
                foreach (($variant['prices'] ?? []) as $price) {
                    if ($price === null) {
                        continue;
                    }
                    $variantPriceRows[] = [
                        'id' => ! empty($price['id']) ? (string) $price['id'] : sprintf('%s::%s', (string) $variant['id'], (string) ($price['channel'] ?? '')),
                        'variant_id' => (string) $variant['id'],
                        'channel' => (string) ($price['channel'] ?? ''),
                        'price' => (int) ($price['price'] ?? 0),
                        'updated_at' => $price['updated_at'] ?? ($variant['updated_at'] ?? null),
                    ];
                }
            }
        }

        $paymentRows = collect($snapshot['paymentMethods'] ?? [])
            ->filter(fn ($item) => ! empty($item['id']))
            ->map(fn ($item) => [
                'id' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'sort_order' => (int) ($item['sort_order'] ?? 0),
                'updated_at' => $item['updated_at'] ?? null,
            ])
            ->values()
            ->all();

        $discountRows = collect($snapshot['discountPackages'] ?? [])
            ->filter(fn ($item) => ! empty($item['id']))
            ->map(function ($item) {
                $productIds = collect($item['product_ids'] ?? [])
                    ->filter()
                    ->map(fn ($value) => (string) $value)
                    ->sort()
                    ->values()
                    ->all();
                $customerIds = collect($item['customer_ids'] ?? [])
                    ->filter()
                    ->map(fn ($value) => (string) $value)
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => (string) $item['id'],
                    'code' => (string) ($item['code'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'applies_to' => (string) ($item['applies_to'] ?? ''),
                    'discount_type' => (string) ($item['discount_type'] ?? ''),
                    'discount_value' => (int) ($item['discount_value'] ?? 0),
                    'product_ids' => $productIds,
                    'customer_ids' => $customerIds,
                    'updated_at' => $item['updated_at'] ?? null,
                ];
            })
            ->values()
            ->all();

        $taxRows = ! empty($snapshot['tax']) ? [[
            'id' => (string) ($snapshot['tax']['id'] ?? ''),
            'name' => (string) ($snapshot['tax']['name'] ?? ''),
            'percent' => (int) ($snapshot['tax']['percent'] ?? $snapshot['tax_percent'] ?? 0),
            'updated_at' => $snapshot['tax']['updated_at'] ?? null,
        ]] : [];

        return [
            'categories' => $categoryRows,
            'products' => $productRows,
            'variants' => $variantRows,
            'variant_prices' => $variantPriceRows,
            'payment_methods' => $paymentRows,
            'discounts' => $discountRows,
            'tax' => $taxRows,
        ];
    }

    protected function buildManifestFromDomainRows(string $outletCode, string $channel, array $domainRows, array $channels, string $generatedAt): array
    {
        $channelOrder = [
            SalesChannels::DINE_IN => 1,
            SalesChannels::TAKEAWAY => 2,
            SalesChannels::DELIVERY => 3,
            'PROVISION' => 90,
        ];

        $channels = collect($channels)
            ->filter()
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->unique()
            ->sortBy(fn (string $value) => sprintf('%03d:%s', $channelOrder[$value] ?? 999, $value))
            ->values()
            ->all();

        $domains = [
            'categories' => $this->domainPayload($domainRows['categories'] ?? []),
            'products' => $this->domainPayload($domainRows['products'] ?? []),
            'variants' => $this->domainPayload($domainRows['variants'] ?? []),
            'variant_prices' => $this->domainPayload($domainRows['variant_prices'] ?? []),
            'payment_methods' => $this->domainPayload($domainRows['payment_methods'] ?? []),
            'discounts' => $this->domainPayload($domainRows['discounts'] ?? []),
            'tax' => $this->domainPayload($domainRows['tax'] ?? []),
        ];

        return [
            'outlet_code' => $outletCode,
            'channel' => strtoupper(trim($channel)),
            'channels' => $channels,
            'generated_at' => $generatedAt,
            'contract' => 'pos-master-manifest',
            'version' => 3,
            'domains' => $domains,
            'checksum' => $this->checksum([
                'outlet_code' => $outletCode,
                'channel' => strtoupper(trim($channel)),
                'channels' => $channels,
                'version' => 3,
                'domains' => collect($domains)->map(fn (array $domain) => [
                    'count' => $domain['count'] ?? 0,
                    'signature' => $domain['signature'] ?? null,
                    'tracked' => $domain['tracking']['tracked'] ?? true,
                ])->all(),
            ]),
        ];
    }

    protected function uniqueDomainRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = json_encode($this->normalizeForChecksum($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($key === false) {
                continue;
            }
            $unique[$key] = $row;
        }

        return array_values($unique);
    }

    protected function domainPayload(array $rows): array
    {
        $orderedRows = collect($rows)
            ->map(fn ($row) => is_array($row) ? $row : (array) $row)
            ->sortBy(fn (array $row) => json_encode($this->normalizeForChecksum($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->values();

        $updatedAt = $orderedRows
            ->pluck('updated_at')
            ->filter()
            ->sort()
            ->last();

        return [
            'count' => $orderedRows->count(),
            'updated_at' => $updatedAt ?: null,
            'signature' => $this->checksum($orderedRows->all()),
            'tracking' => [
                'mode' => 'provisioned_outlet_active_snapshot',
                'tracked' => true,
            ],
        ];
    }

    protected function normalizeForChecksum(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->normalizeForChecksum($item), $value);
            }

            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeForChecksum($item);
            }
        }

        return $value;
    }

    protected function checksum(mixed $value): string
    {
        return hash('sha256', json_encode($this->normalizeForChecksum($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
