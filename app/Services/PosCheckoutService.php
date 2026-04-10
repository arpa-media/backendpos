<?php

namespace App\Services;

use App\Services\MarkingService;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Tax;
use App\Models\User;
use App\Support\PaymentMethodTypes;
use App\Support\SaleAmountBreakdown;
use App\Support\SaleRounding;
use App\Support\SalesChannels;
use App\Support\SaleStatuses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    private function isOfflineLikePayload(array $payload): bool
    {
        if (trim((string) ($payload['client_sync_id'] ?? '')) !== '') {
            return true;
        }

        if (is_array($payload['offline_snapshot'] ?? null) && !empty($payload['offline_snapshot'])) {
            return true;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (array_key_exists('unit_price_snapshot', $row) || array_key_exists('line_total_snapshot', $row)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDiscountSquadNisj(mixed $value): string
    {
        return trim((string) $value);
    }

    private function resolveSquadDiscountPackageFromSnapshots(array $discountSnapshots): ?array
    {
        foreach ($discountSnapshots as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (strtoupper((string) ($row['applies_to'] ?? '')) === 'SQUAD') {
                return $row;
            }
        }

        return null;
    }

    private function normalizedDiscountSpec(string $appliesTo, string $discountType, int $discountValue): array
    {
        $applies = strtoupper(trim((string) $appliesTo));
        if ($applies === 'SQUAD') {
            return ['type' => 'PERCENT', 'value' => 20];
        }

        $type = strtoupper(trim((string) $discountType));
        if (!in_array($type, ['NONE', 'PERCENT', 'FIXED'], true)) {
            $type = 'NONE';
        }

        return [
            'type' => $type,
            'value' => max(0, (int) $discountValue),
        ];
    }

    /**
     * Checkout (atomic).
     *
     * NOTE:
     * - Outlet is resolved by middleware (OutletScope) and passed in from controller.
     * - Tax percent from request is ignored; tax is computed server-side from active default tax.
     */
    public function checkout(User $user, string $outletId, array $payload): Sale
    {
        if ($this->isOfflineLikePayload($payload)) {
            $payload = $this->rescueOfflinePayload($outletId, $payload);
        }

        $payloadChannel = $payload['channel'] ?? null;
        $clientSyncId = isset($payload['client_sync_id']) ? trim((string) $payload['client_sync_id']) : null;
        $queueNo = trim((string) ($payload['queue_no'] ?? ''));
        $billName = isset($payload['bill_name']) ? trim((string) $payload['bill_name']) : null;
        $customerId = $payload['customer_id'] ?? null;
        $tableChamber = trim((string) ($payload['table_chamber'] ?? ''));
        $tableNumber = trim((string) ($payload['table_number'] ?? ''));
        $onlineOrderSource = strtoupper(trim((string) ($payload['online_order_source'] ?? 'ONLINE')));
        if (!in_array($onlineOrderSource, ['ONLINE', 'GOFOOD', 'GRABFOOD', 'SHOPEEFOOD'], true)) {
            $onlineOrderSource = 'ONLINE';
        }

        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->select(['code', 'timezone'])
            ->first();

        $outletTimezone = $outletRow?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $transactionAtInput = isset($payload['transaction_at']) ? trim((string) $payload['transaction_at']) : '';
        $transactionAtTz = $this->resolveTransactionMoment($transactionAtInput !== '' ? $transactionAtInput : null, $outletTimezone);
        $transactionAtUtc = $transactionAtTz->copy()->utc();
        $offlineSnapshot = is_array($payload['offline_snapshot'] ?? null) ? $payload['offline_snapshot'] : [];
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $hasItemSnapshotPricing = collect($rawItems)->contains(function ($row) {
            return is_array($row)
                && (array_key_exists('unit_price_snapshot', $row) || array_key_exists('line_total_snapshot', $row));
        });
        $preferredSaleNumber = trim((string) ($offlineSnapshot['preferred_sale_number'] ?? ''));

        // Discount payload (backward compatible with Phase-1 fields)
        // - Manual (single): discount: { type, value, reason }
        // - Package (single): discount: { discount_id }
        // - Package (multiple): discounts: [{ discount_id }, ...]
        $discount = is_array($payload['discount'] ?? null) ? $payload['discount'] : [];
        $discounts = is_array($payload['discounts'] ?? null) ? $payload['discounts'] : [];

        $discountId = $discount['discount_id'] ?? null;
        $discountType = strtoupper((string) ($discount['type'] ?? 'NONE'));
        $discountValue = (int) ($discount['value'] ?? 0);
        $discountReason = $discount['reason'] ?? ($payload['discount_reason'] ?? null);
        $discountSquadNisjInput = $this->normalizeDiscountSquadNisj($payload['discount_squad_nisj'] ?? null);

        // IMPORTANT: ignore tax_percent input (legacy Phase 1)
        $defaultTax = Tax::query()
            ->whereHas('outlets', function ($query) use ($outletId) {
                $query->where('outlets.id', $outletId)
                    ->where('outlet_tax.is_active', true)
                    ->where('outlet_tax.is_default', true);
            })
            ->with(['outlets' => function ($query) use ($outletId) {
                $query->where('outlets.id', $outletId);
            }])
            ->first();

        $taxId = $defaultTax ? (string) $defaultTax->id : null;
        // IMPORTANT (Patch-8): UI/receipt should never show "No Tax" string.
        // If there is no active default tax, we still label it as "Tax" with 0%.
        $taxName = $defaultTax
            ? ('Tax (' . (string) ($defaultTax->display_name ?: $defaultTax->name) . ')')
            : 'Tax';
        $taxPercent = $defaultTax ? (int) $defaultTax->percent : 0;
        $taxPercent = max(0, min(100, $taxPercent));

        $items = $payload['items'] ?? null;
        $payment = $payload['payment'] ?? null;

        if (!$payloadChannel) {
            throw ValidationException::withMessages([
                'channel' => ['Channel is required.'],
            ]);
        }

        if (!is_string($billName) || trim($billName) === '') {
            throw ValidationException::withMessages([
                'bill_name' => ['Bill name is required.'],
            ]);
        }

        if (!is_array($items) || count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => ['Items is required.'],
            ]);
        }

        if (!is_array($payment) || empty($payment['payment_method_id']) || !isset($payment['amount'])) {
            throw ValidationException::withMessages([
                'payment' => ['Payment is invalid.'],
            ]);
        }

        return DB::transaction(function () use (
            $outletId,
            $user,
            $payloadChannel,
            $items,
            $payment,
            $payload,
            $billName,
            $customerId,
            $tableChamber,
            $tableNumber,
            $discountType,
            $discountSquadNisjInput,
            $discountValue,
            $discountReason,
            $discountId,
            $discounts,
            $taxId,
            $taxName,
            $taxPercent,
            $clientSyncId,
            $onlineOrderSource,
            $queueNo,
            $offlineSnapshot,
            $preferredSaleNumber,
            $transactionAtTz,
            $transactionAtUtc,
            $outletTimezone
        ) {

            // 0) Optional: validate customer globally.
            // Customer master is now treated as cross-outlet for POS selection,
            // while the sale/payment still belongs to the active outlet.
            if ($clientSyncId !== null && $clientSyncId !== '') {
                $existingSale = Sale::query()
                    ->where('client_sync_id', $clientSyncId)
                    ->where('outlet_id', $outletId)
                    ->first();

                if ($existingSale) {
                    return $existingSale->load(['items', 'payments', 'customer', 'outlet']);
                }
            }

            $customer = null;
            if (!empty($customerId)) {
                $customer = Customer::query()
                    ->where('id', $customerId)
                    ->first();

                if (!$customer && !empty($payload['customer_phone'])) {
                    $customer = Customer::query()
                        ->where('phone', preg_replace('/\D+/', '', (string) $payload['customer_phone']))
                        ->first();
                }

                if (!$customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['Customer not found.'],
                    ]);
                }
            }

            $useOfflineSnapshot = !empty($offlineSnapshot) || $hasItemSnapshotPricing;

            // 1) Validate payment method: global active + enabled in outlet via pivot
            $pm = PaymentMethod::query()
                ->where('id', $payment['payment_method_id'])
                ->where('is_active', true)
                ->whereHas('outlets', function ($q) use ($outletId) {
                    $q->where('outlets.id', $outletId)
                        ->where('outlet_payment_method.is_active', true);
                })
                ->first();

            if (!$pm && $useOfflineSnapshot) {
                $pm = PaymentMethod::query()
                    ->where('id', $payment['payment_method_id'])
                    ->first();
            }

            if (!$pm) {
                throw ValidationException::withMessages([
                    'payment.payment_method_id' => ['Payment method not found/disabled for this outlet.'],
                ]);
            }

            // 2) Normalize items:
            // - allow variant_id nullable when product has exactly 1 active variant (for this outlet)
            // - Patch-6: allow per-item channel (DINE_IN/TAKEAWAY/DELIVERY). If omitted, use payload.channel.
            $normalized = [];
            $missingVariantByProduct = [];
            $productIds = [];
            $channelsInSale = [];

            foreach ($items as $idx => $row) {
                $productId = $row['product_id'] ?? null;
                $variantId = $row['variant_id'] ?? null;
                $qty = (int) ($row['qty'] ?? 0);
                $itemChannel = strtoupper((string) ($row['channel'] ?? $payloadChannel));

                if (!in_array($itemChannel, [SalesChannels::DINE_IN, SalesChannels::TAKEAWAY, SalesChannels::DELIVERY], true)) {
                    throw ValidationException::withMessages([
                        "items.$idx.channel" => ['Invalid channel.'],
                    ]);
                }

                if (!$productId) {
                    throw ValidationException::withMessages([
                        "items.$idx.product_id" => ['Product is required.'],
                    ]);
                }

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.$idx.qty" => ['Qty must be greater than 0.'],
                    ]);
                }

                $productIds[] = (string) $productId;

                $channelsInSale[] = $itemChannel;

                if (empty($variantId)) {
                    $missingVariantByProduct[(string) $productId][] = $idx;
                }

                $normalized[$idx] = [
                    'channel' => $itemChannel,
                    'product_id' => (string) $productId,
                    'variant_id' => $variantId ? (string) $variantId : null,
                    'qty' => $qty,
                    'note' => isset($row['note']) ? trim((string) $row['note']) : null,

                    // Hotfix-001:
                    // Preserve offline pricing/name/category snapshots during normalization.
                    // Without this, offline sync payloads that already contain
                    // unit_price_snapshot / line_total_snapshot lose those fields here,
                    // then later fall back to live ProductVariantPrice lookup and can fail
                    // with "Price not found for channel ...".
                    'product_name' => isset($row['product_name']) ? trim((string) $row['product_name']) : null,
                    'variant_name' => isset($row['variant_name']) ? trim((string) $row['variant_name']) : null,
                    'unit_price_snapshot' => is_numeric($row['unit_price_snapshot'] ?? null)
                        ? (int) round((float) $row['unit_price_snapshot'])
                        : null,
                    'line_total_snapshot' => is_numeric($row['line_total_snapshot'] ?? null)
                        ? (int) round((float) $row['line_total_snapshot'])
                        : null,
                    'category_id_snapshot' => isset($row['category_id_snapshot']) && $row['category_id_snapshot'] !== null
                        ? (string) $row['category_id_snapshot']
                        : null,
                    'category_kind_snapshot' => isset($row['category_kind_snapshot']) && $row['category_kind_snapshot'] !== null
                        ? strtoupper(trim((string) $row['category_kind_snapshot']))
                        : null,
                    'category_name_snapshot' => isset($row['category_name_snapshot']) ? trim((string) $row['category_name_snapshot']) : null,
                    'category_slug_snapshot' => isset($row['category_slug_snapshot']) ? trim((string) $row['category_slug_snapshot']) : null,
                ];
            }

            $channelsInSale = array_values(array_unique(array_filter($channelsInSale)));

            // Patch-6 rule: allow MIXED only for DINE_IN + TAKEAWAY.
            // DELIVERY cannot be mixed with others in Phase 1.
            if (count($channelsInSale) > 1) {
                $allowedMixed = [SalesChannels::DINE_IN, SalesChannels::TAKEAWAY];
                $diff = array_diff($channelsInSale, $allowedMixed);
                if (!empty($diff)) {
                    throw ValidationException::withMessages([
                        'channel' => ['Mixed channel is only allowed for Dine In + Takeaway in this version.'],
                    ]);
                }
            }

            $saleChannel = count($channelsInSale) === 1 ? $channelsInSale[0] : SalesChannels::MIXED;

            // 2a) Ensure products are active for this outlet (pivot outlet_product)
            $productIds = array_values(array_unique($productIds));
            if (!$useOfflineSnapshot) {
                $activeProductCount = (int) DB::table('outlet_product')
                    ->where('outlet_id', $outletId)
                    ->whereIn('product_id', $productIds)
                    ->where('is_active', true)
                    ->count();

                if ($activeProductCount !== count($productIds)) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more products not active for this outlet.'],
                    ]);
                }
            }

            if (!empty($missingVariantByProduct)) {
                $ids = array_keys($missingVariantByProduct);

                $variantsByProduct = ProductVariant::query()
                    ->where('outlet_id', $outletId)
                    ->whereIn('product_id', $ids)
                    ->where('is_active', true)
                    ->get()
                    ->groupBy('product_id');

                foreach ($missingVariantByProduct as $productId => $indexes) {
                    $variantsForProduct = $variantsByProduct->get($productId, collect());

                    // Variant required if product has more than 1 active variant.
                    if ($variantsForProduct->count() !== 1) {
                        foreach ($indexes as $i) {
                            throw ValidationException::withMessages([
                                "items.$i.variant_id" => ['Variant is required for this product.'],
                            ]);
                        }
                    }

                    // If only 1 variant, auto select it.
                    $onlyVariant = $variantsForProduct->first();
                    foreach ($indexes as $i) {
                        $normalized[$i]['variant_id'] = (string) $onlyVariant->id;
                    }
                }
            }

            // 3) Load variants scoped by outlet
            $variantIds = collect($normalized)->pluck('variant_id')->filter()->unique()->values()->all();

            if (count($variantIds) === 0) {
                throw ValidationException::withMessages([
                    'items' => ['One or more items missing variant_id.'],
                ]);
            }

            $variantsQuery = ProductVariant::query()
                ->where('outlet_id', $outletId)
                ->whereIn('id', $variantIds);

            if (!$useOfflineSnapshot) {
                $variantsQuery->where('is_active', true);
            }

            $variants = $variantsQuery
                ->with(['product', 'product.category'])
                ->get()
                ->keyBy('id');

            if ($variants->count() !== count($variantIds)) {
                throw ValidationException::withMessages([
                    'items' => ['One or more variants not found/disabled for this outlet.'],
                ]);
            }

            // 4) Load prices for required channels (Patch-6: per-item channel)
            $prices = $useOfflineSnapshot
                ? collect()
                : ProductVariantPrice::query()
                    ->where('outlet_id', $outletId)
                    ->whereIn('variant_id', $variantIds)
                    ->whereIn('channel', $channelsInSale)
                    ->get()
                    ->keyBy(fn ($row) => (string) $row->variant_id.'|'.(string) $row->channel);

            // 5) Compute subtotal + build sale items
            $subtotal = 0;
            $saleItems = [];

            foreach ($normalized as $idx => $row) {
                $itemChannel = (string) $row['channel'];
                $variantId = (string) $row['variant_id'];
                $productId = (string) $row['product_id'];
                $qty = (int) $row['qty'];
                $note = isset($row['note']) ? trim((string) $row['note']) : null;
                $note = $note === '' ? null : $note;

                $variant = $variants->get($variantId);
                if (!$variant) {
                    throw ValidationException::withMessages([
                        "items.$idx.variant_id" => ['Variant not found.'],
                    ]);
                }

                // Guard: variant must belong to selected product
                if ((string) $variant->product_id !== $productId) {
                    throw ValidationException::withMessages([
                        "items.$idx.variant_id" => ['Variant does not belong to selected product.'],
                    ]);
                }

                $snapshotUnitPrice = is_numeric($row['unit_price_snapshot'] ?? null) ? (int) round((float) $row['unit_price_snapshot']) : null;
                $snapshotLineTotal = is_numeric($row['line_total_snapshot'] ?? null) ? (int) round((float) $row['line_total_snapshot']) : null;
                $hasSnapshotPricing = ($snapshotUnitPrice !== null && $snapshotUnitPrice >= 0)
                    || ($snapshotLineTotal !== null && $snapshotLineTotal >= 0);

                if ($hasSnapshotPricing) {
                    if ($snapshotUnitPrice === null || $snapshotUnitPrice < 0) {
                        $unitPrice = $qty > 0 ? (int) round($snapshotLineTotal / max(1, $qty)) : 0;
                    } else {
                        $unitPrice = $snapshotUnitPrice;
                    }

                    $lineTotal = $snapshotLineTotal !== null && $snapshotLineTotal >= 0
                        ? $snapshotLineTotal
                        : ($unitPrice * $qty);
                } else {
                    $priceRow = $prices->get($variantId.'|'.$itemChannel);
                    if (!$priceRow) {
                        throw ValidationException::withMessages([
                            "items.$idx.variant_id" => ["Price not found for channel $itemChannel."],
                        ]);
                    }

                    $unitPrice = (int) $priceRow->price;
                    $lineTotal = $unitPrice * $qty;
                }

                $subtotal += $lineTotal;

                $saleItems[] = [
                    'outlet_id' => $outletId,
                    'product_id' => (string) $variant->product_id,
                    'variant_id' => (string) $variant->id,
                    'channel' => $itemChannel,
                    'product_name' => (string) ($row['product_name'] ?? optional($variant->product)->name ?? ''),
                    'variant_name' => (string) ($row['variant_name'] ?? $variant->name ?? ''),
                    'category_kind_snapshot' => (string) ($row['category_kind_snapshot'] ?? optional(optional($variant->product)->category)->kind ?? 'OTHER'),
                    'note' => $note,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            // 6) Discount engine
            // Supported payloads:
            // - Manual (single): discount: { type, value, reason }
            // - Package (single): discount: { discount_id }
            // - Package (multiple): discounts: [{ discount_id }, ...]

            $now = now();

            // Normalize discount package IDs
            $packageIds = [];
            if (is_array($discounts) && count($discounts) > 0) {
                foreach ($discounts as $row) {
                    $id = is_array($row) ? ($row['discount_id'] ?? null) : null;
                    if (is_string($id) && trim($id) !== '') {
                        $packageIds[] = trim($id);
                    }
                }
            } elseif (!empty($discountId) && is_string($discountId) && trim($discountId) !== '') {
                $packageIds[] = trim($discountId);
            }
            $packageIds = array_values(array_unique(array_filter($packageIds)));

            $discountPackages = collect();
            if (!$useOfflineSnapshot && count($packageIds) > 0) {
                $discountPackages = Discount::query()
                    ->where('outlet_id', $outletId)
                    ->whereIn('id', $packageIds)
                    ->where('is_active', true)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function ($q) use ($now) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                    })
                    ->with(['products:id', 'customers:id'])
                    ->get()
                    ->keyBy(fn (Discount $d) => (string) $d->id);

                // ensure all ids resolved
                foreach ($packageIds as $id) {
                    if (!$discountPackages->has($id)) {
                        throw ValidationException::withMessages([
                            'discounts' => ["Discount package not found/disabled for this outlet: $id"],
                        ]);
                    }
                }
                $discountPackages = $discountPackages->values();
            }

            // If packages are used, ignore manual discount inputs.
            $usePackages = $discountPackages->count() > 0;

            $discountSnapshots = [];
            $discountAmount = 0;
            $discountPackage = null;

            if ($useOfflineSnapshot) {
                $discountType = strtoupper((string) ($offlineSnapshot['discount_type'] ?? $discountType ?? 'NONE'));
                if (!in_array($discountType, ['NONE', 'PERCENT', 'FIXED'], true)) {
                    $discountType = 'NONE';
                }
                $discountValue = (int) ($offlineSnapshot['discount_value'] ?? $discountValue ?? 0);
                $discountReason = $offlineSnapshot['discount_reason'] ?? $discountReason;
                $discountAmount = max(0, min((int) $subtotal, (int) ($offlineSnapshot['discount_amount'] ?? 0)));
                $discountSnapshots = is_array($offlineSnapshot['discounts_snapshot'] ?? null) ? $offlineSnapshot['discounts_snapshot'] : [];
                if (!empty($discountSnapshots[0]['id'])) {
                    $firstDiscount = $discountSnapshots[0];
                    $firstAppliesTo = (string) ($firstDiscount['applies_to'] ?? '');
                    $firstSpec = $this->normalizedDiscountSpec($firstAppliesTo, (string) ($firstDiscount['discount_type'] ?? ''), (int) ($firstDiscount['discount_value'] ?? 0));
                    $discountPackage = (object) [
                        'id' => (string) ($firstDiscount['id'] ?? ''),
                        'code' => (string) ($firstDiscount['code'] ?? ''),
                        'name' => (string) ($firstDiscount['name'] ?? ''),
                        'applies_to' => $firstAppliesTo,
                        'discount_type' => $firstSpec['type'],
                        'discount_value' => (int) $firstSpec['value'],
                    ];
                }
            } elseif ($usePackages) {
                foreach ($discountPackages as $pkg) {
                    $appliesTo = strtoupper((string) $pkg->applies_to);

                    // base by applies_to
                    $base = $subtotal;
                    if ($appliesTo === 'PRODUCT') {
                        $productIdsForDiscount = $pkg->products->pluck('id')->map(fn ($x) => (string) $x)->all();
                        $base = 0;
                        foreach ($saleItems as $row) {
                            if (in_array((string) $row['product_id'], $productIdsForDiscount, true)) {
                                $base += (int) $row['line_total'];
                            }
                        }
                    } elseif ($appliesTo === 'CUSTOMER') {
                        if (!$customer) {
                            throw ValidationException::withMessages([
                                'customer_id' => ['Customer is required for this discount.'],
                            ]);
                        }
                        $customerIdsForDiscount = $pkg->customers->pluck('id')->map(fn ($x) => (string) $x)->all();
                        if (!in_array((string) $customer->id, $customerIdsForDiscount, true)) {
                            throw ValidationException::withMessages([
                                'customer_id' => ['Customer not eligible for this discount.'],
                            ]);
                        }
                        // CUSTOMER => subtotal semua cart (per instruksi)
                        $base = $subtotal;
                    } else {
                        $base = $subtotal;
                    }

                    $base = max(0, (int) $base);
                    $amt = 0;
                    $spec = $this->normalizedDiscountSpec($appliesTo, (string) $pkg->discount_type, (int) $pkg->discount_value);
                    $t = $spec['type'];
                    $v = (int) $spec['value'];
                    if ($t === 'PERCENT') {
                        $pct = max(0, min(100, $v));
                        $amt = (int) floor(($base * $pct) / 100);
                    } elseif ($t === 'FIXED') {
                        $amt = min($base, max(0, $v));
                    }
                    $amt = max(0, $amt);

                    $discountSnapshots[] = [
                        'id' => (string) $pkg->id,
                        'code' => (string) $pkg->code,
                        'name' => (string) $pkg->name,
                        'applies_to' => $appliesTo,
                        'discount_type' => $t,
                        'discount_value' => (int) $v,
                        'base' => (int) $base,
                        'amount' => (int) $amt,
                    ];

                    $discountAmount += $amt;
                }
            } else {
                // Manual discount
                $discountType = in_array($discountType, ['NONE', 'PERCENT', 'FIXED'], true) ? $discountType : 'NONE';
                $discountValue = max(0, (int) $discountValue);

                $base = (int) $subtotal;
                if ($discountType === 'PERCENT') {
                    $pct = max(0, min(100, $discountValue));
                    $discountAmount = (int) floor(($base * $pct) / 100);
                } elseif ($discountType === 'FIXED') {
                    $discountAmount = min($base, $discountValue);
                } else {
                    $discountAmount = 0;
                }
            }

            // cap at subtotal
            $discountAmount = max(0, min((int) $subtotal, (int) $discountAmount));
            $taxableBase = max(0, (int) $subtotal - (int) $discountAmount);

            // Snapshot helpers (for receipts/history)
            if (!$useOfflineSnapshot && $usePackages) {
                $discountPackage = $discountPackages->first();
                // For backward compatibility fields, keep the first package spec.
                $discountType = $discountPackage ? $this->normalizedDiscountSpec((string) $discountPackage->applies_to, (string) $discountPackage->discount_type, (int) $discountPackage->discount_value)['type'] : 'NONE';
                $discountValue = $discountPackage ? (int) $this->normalizedDiscountSpec((string) $discountPackage->applies_to, (string) $discountPackage->discount_type, (int) $discountPackage->discount_value)['value'] : 0;
                $codes = collect($discountSnapshots)->pluck('code')->filter()->values()->all();
                $discountReason = !empty($codes) ? implode('+', $codes) : null;
            }

            $selectedSquadPackage = null;
            $selectedSquadDiscountModel = null;
            $selectedSquadUser = null;
            $selectedSquadPeriodKey = null;

            if (!$useOfflineSnapshot && $usePackages) {
                $squadPackages = $discountPackages
                    ->filter(fn (Discount $pkg) => strtoupper((string) $pkg->applies_to) === 'SQUAD')
                    ->values();

                if ($squadPackages->count() > 0) {
                    if ($discountPackages->count() > 1) {
                        throw ValidationException::withMessages([
                            'discounts' => ['Discount squad hanya bisa dipilih sendiri.'],
                        ]);
                    }

                    if ($discountSquadNisjInput === '') {
                        throw ValidationException::withMessages([
                            'discount_squad_nisj' => ['NISJ squad wajib dipilih untuk discount squad.'],
                        ]);
                    }

                    $selectedSquadDiscountModel = $squadPackages->first();
                    $selectedSquadPackage = [
                        'id' => (string) $selectedSquadDiscountModel->id,
                        'code' => (string) $selectedSquadDiscountModel->code,
                        'name' => (string) $selectedSquadDiscountModel->name,
                        'applies_to' => 'SQUAD',
                    ];
                }
            }

            if ($useOfflineSnapshot) {
                $selectedSquadPackage = $this->resolveSquadDiscountPackageFromSnapshots($discountSnapshots);
                if ($selectedSquadPackage !== null) {
                    if (count($discountSnapshots) > 1) {
                        throw ValidationException::withMessages([
                            'discounts' => ['Discount squad hanya bisa dipilih sendiri.'],
                        ]);
                    }

                    if ($discountSquadNisjInput === '') {
                        throw ValidationException::withMessages([
                            'discount_squad_nisj' => ['NISJ squad wajib dipilih untuk discount squad.'],
                        ]);
                    }

                    $selectedSquadDiscountId = trim((string) ($selectedSquadPackage['id'] ?? ''));
                    if ($selectedSquadDiscountId !== '') {
                        $selectedSquadDiscountModel = Discount::query()
                            ->withTrashed()
                            ->where('outlet_id', $outletId)
                            ->whereKey($selectedSquadDiscountId)
                            ->first();
                    }
                }
            }

            if ($selectedSquadPackage !== null) {
                $discountSquadService = app(DiscountSquadService::class);
                $selectedSquadUser = $discountSquadService->findUserByNisj($discountSquadNisjInput);

                if (!$selectedSquadUser) {
                    throw ValidationException::withMessages([
                        'discount_squad_nisj' => ['NISJ squad tidak ditemukan atau tidak aktif.'],
                    ]);
                }

                $selectedSquadPeriodKey = $discountSquadService->currentPeriodKey($outletTimezone);
                if (!$discountSquadService->isAvailableForNisj((string) $selectedSquadUser->nisj, $selectedSquadPeriodKey)) {
                    throw ValidationException::withMessages([
                        'discount_squad_nisj' => ['Jatah discount squad untuk NISJ tersebut sudah terpakai hari ini.'],
                    ]);
                }
            }

            if ($selectedSquadPackage !== null) {
                $forcedSpec = $this->normalizedDiscountSpec('SQUAD', 'PERCENT', 20);
                $discountType = $forcedSpec['type'];
                $discountValue = (int) $forcedSpec['value'];
                $selectedSquadProductIds = $this->normalizeStringIdList(is_array($selectedSquadPackage['product_ids'] ?? null)
                    ? $selectedSquadPackage['product_ids']
                    : ($selectedSquadDiscountModel ? $selectedSquadDiscountModel->products->pluck('id')->all() : []));
                $selectedSquadCustomerIds = $this->normalizeStringIdList(is_array($selectedSquadPackage['customer_ids'] ?? null)
                    ? $selectedSquadPackage['customer_ids']
                    : ($selectedSquadDiscountModel ? $selectedSquadDiscountModel->customers->pluck('id')->all() : []));
                $squadBase = $this->resolveDiscountBase(
                    'SQUAD',
                    (int) $subtotal,
                    $saleItems,
                    $selectedSquadProductIds,
                    $selectedSquadCustomerIds,
                    $customer,
                    $discountSquadNisjInput
                );
                $squadAmount = $this->calculateDiscountAmountFromBase($discountType, $discountValue, $squadBase);
                $discountAmount = max(0, min((int) $subtotal, (int) $squadAmount));

                if (empty($discountSnapshots)) {
                    $discountSnapshots[] = [
                        'id' => (string) ($selectedSquadPackage['id'] ?? ''),
                        'code' => (string) ($selectedSquadPackage['code'] ?? ''),
                        'name' => (string) ($selectedSquadPackage['name'] ?? ''),
                        'applies_to' => 'SQUAD',
                        'discount_type' => $discountType,
                        'discount_value' => $discountValue,
                        'product_ids' => $selectedSquadProductIds,
                        'customer_ids' => $selectedSquadCustomerIds,
                        'base' => (int) $squadBase,
                        'amount' => (int) $discountAmount,
                    ];
                } else {
                    foreach ($discountSnapshots as $i => $snapshot) {
                        if (strtoupper((string) ($snapshot['applies_to'] ?? '')) !== 'SQUAD') {
                            continue;
                        }

                        $discountSnapshots[$i]['discount_type'] = $discountType;
                        $discountSnapshots[$i]['discount_value'] = $discountValue;
                        $discountSnapshots[$i]['product_ids'] = !empty($selectedSquadProductIds)
                            ? $selectedSquadProductIds
                            : $this->normalizeStringIdList((array) ($discountSnapshots[$i]['product_ids'] ?? []));
                        $discountSnapshots[$i]['customer_ids'] = !empty($selectedSquadCustomerIds)
                            ? $selectedSquadCustomerIds
                            : $this->normalizeStringIdList((array) ($discountSnapshots[$i]['customer_ids'] ?? []));
                        $discountSnapshots[$i]['base'] = (int) $this->resolveDiscountBase(
                            'SQUAD',
                            (int) $subtotal,
                            $saleItems,
                            (array) ($discountSnapshots[$i]['product_ids'] ?? []),
                            (array) ($discountSnapshots[$i]['customer_ids'] ?? []),
                            $customer,
                            $discountSquadNisjInput
                        );
                        $discountSnapshots[$i]['amount'] = $this->calculateDiscountAmountFromBase(
                            $discountType,
                            $discountValue,
                            (int) $discountSnapshots[$i]['base']
                        );
                    }
                }

                if (!$discountPackage) {
                    $discountPackage = (object) [
                        'id' => (string) ($selectedSquadPackage['id'] ?? ''),
                        'code' => (string) ($selectedSquadPackage['code'] ?? ''),
                        'name' => (string) ($selectedSquadPackage['name'] ?? ''),
                        'applies_to' => 'SQUAD',
                        'discount_type' => $discountType,
                        'discount_value' => $discountValue,
                    ];
                }

                $discountReason = (string) (($selectedSquadPackage['code'] ?? null) ?: ($selectedSquadPackage['name'] ?? null) ?: 'SQUAD');
            }

            // 7) Tax (default tax per outlet, except online/delivery which are always no-tax)
            $isOnlineNoTax = $saleChannel === SalesChannels::DELIVERY;
            if ($useOfflineSnapshot) {
                $effectiveTaxId = $isOnlineNoTax ? null : ($offlineSnapshot['tax_id'] ?? $taxId);
                $effectiveTaxName = $isOnlineNoTax
                    ? 'Tax'
                    : (string) ($offlineSnapshot['tax_name_snapshot'] ?? $taxName ?? 'Tax');
                $effectiveTaxPercent = $isOnlineNoTax
                    ? 0
                    : max(0, min(100, (int) ($offlineSnapshot['tax_percent_snapshot'] ?? $taxPercent ?? 0)));
                $serviceChargeTotal = 0;
                $roundingTotal = (int) ($offlineSnapshot['rounding_total'] ?? 0);

                // Canonical server-side rule: DELIVERY is always no-tax, even when legacy
                // offline payloads still carry tax snapshots from the client.
                $canonicalAmounts = SaleAmountBreakdown::canonical(
                    (int) ($offlineSnapshot['subtotal'] ?? $subtotal),
                    (int) $discountAmount,
                    (int) $effectiveTaxPercent,
                    (int) $roundingTotal,
                    0
                );

                $taxTotal = (int) $canonicalAmounts['tax_total'];
                $grandTotalBeforeRounding = (int) $canonicalAmounts['before_rounding'];
                $grandTotal = (int) $canonicalAmounts['grand_total'];
            } else {
                $effectiveTaxId = $isOnlineNoTax ? null : $taxId;
                $effectiveTaxName = $isOnlineNoTax ? 'Tax' : $taxName;
                $effectiveTaxPercent = $isOnlineNoTax ? 0 : (int) $taxPercent;

                $taxTotal = (int) floor(($taxableBase * $effectiveTaxPercent) / 100);
                $serviceChargeTotal = 0;

                $roundingSnapshot = SaleRounding::apply((int) ($taxableBase + $taxTotal + $serviceChargeTotal));
                $roundingTotal = (int) ($roundingSnapshot['rounding_total'] ?? 0);
                $grandTotalBeforeRounding = (int) ($roundingSnapshot['before_rounding'] ?? 0);
                $grandTotal = (int) ($roundingSnapshot['after_rounding'] ?? 0);
            }
            $marking = app(MarkingService::class)->determineNextMarking($outletId);

            // 8) Payment rule
            $inputPaid = (int) ($payment['amount'] ?? 0);
            if ($useOfflineSnapshot) {
                $resolvedPayment = SaleAmountBreakdown::resolvePaymentSnapshot(
                    (string) ($offlineSnapshot['payment_method_type'] ?? $payment['payment_method_type_snapshot'] ?? $pm->type),
                    (int) $grandTotal,
                    (int) ($offlineSnapshot['paid_total'] ?? $inputPaid ?? $grandTotal),
                    (int) ($offlineSnapshot['change_total'] ?? 0)
                );
                $paid = (int) $resolvedPayment['paid_total'];
                $change = (int) $resolvedPayment['change_total'];
                if ((string) ($payment['payment_method_type_snapshot'] ?? $pm->type) === PaymentMethodTypes::CASH && $paid < $grandTotal) {
                    throw ValidationException::withMessages([
                        'payment.amount' => ['Paid amount is less than grand total.'],
                    ]);
                }
            } elseif ((string) $pm->type !== PaymentMethodTypes::CASH) {
                // NON_CASH: auto paid = grandTotal (ignore input amount)
                $paid = $grandTotal;
                $change = 0;
            } else {
                // CASH: require paid >= grandTotal
                $paid = $inputPaid;
                if ($paid < $grandTotal) {
                    throw ValidationException::withMessages([
                        'payment.amount' => ['Paid amount is less than grand total.'],
                    ]);
                }
                $change = $paid - $grandTotal;
            }

            // 9) Create Sale
            $sale = Sale::query()->forceCreate([
                'outlet_id' => $outletId,
                'cashier_id' => (string) $user->id,
                'cashier_name' => (string) ($user->name ?? ''),

                'client_sync_id' => $clientSyncId ?: null,
                'sale_number' => $this->resolveRequestedSaleNumber($outletId, $preferredSaleNumber, $transactionAtTz),
                'queue_no' => $this->resolveRequestedQueueNumber($outletId, $queueNo !== '' ? $queueNo : null, $transactionAtTz, $clientSyncId),
                'channel' => (string) $saleChannel,
                'online_order_source' => (string) $onlineOrderSource,
                'status' => SaleStatuses::PAID,

                'bill_name' => (string) $billName,
                'customer_id' => $customer ? (string) $customer->id : null,
                'table_chamber' => $tableChamber !== '' ? $tableChamber : null,
                'table_number' => $tableNumber !== '' ? $tableNumber : null,

                'subtotal' => $useOfflineSnapshot ? (int) ($offlineSnapshot['subtotal'] ?? $subtotal) : $subtotal,

                // Discount fields
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'discount_reason' => $discountReason ?: null,

                // Discount package snapshot (optional)
                'discount_id' => $discountPackage ? (string) $discountPackage->id : null,
                'discount_code_snapshot' => $discountPackage ? (string) $discountPackage->code : null,
                'discount_name_snapshot' => $discountPackage ? (string) $discountPackage->name : null,
                'discount_applies_to_snapshot' => $discountPackage ? (string) $discountPackage->applies_to : null,

                // Multiple packages snapshot (json)
                'discounts_snapshot' => (!empty($discountSnapshots) || $usePackages) ? $discountSnapshots : null,
                'discount_squad_user_id' => $selectedSquadUser ? (string) $selectedSquadUser->id : null,
                'discount_squad_nisj' => $selectedSquadUser ? (string) ($selectedSquadUser->nisj ?? '') : null,
                'discount_squad_name' => $selectedSquadUser ? (string) ($selectedSquadUser->name ?? '') : null,
                'discount_squad_period_key' => $selectedSquadPeriodKey,

                // Backward compat
                'discount_total' => $discountAmount,

                // Tax snapshot
                'tax_id' => $effectiveTaxId,
                'tax_name_snapshot' => $effectiveTaxName,
                'tax_percent_snapshot' => $effectiveTaxPercent,

                // Canonical tax amount in Phase 1 schema
                'tax_total' => $taxTotal,

                'service_charge_total' => $serviceChargeTotal,
                'rounding_total' => $roundingTotal,
                'grand_total' => $grandTotal,
                'paid_total' => $paid,
                'change_total' => $change,
                'marking' => $marking,

                // snapshots
                'payment_method_name' => (string) ($offlineSnapshot['payment_method_name'] ?? $payment['payment_method_name_snapshot'] ?? $pm->name ?? ''),
                'payment_method_type' => (string) ($offlineSnapshot['payment_method_type'] ?? $payment['payment_method_type_snapshot'] ?? $pm->type ?? ''),

                'note' => $payload['note'] ?? null,
                'created_at' => $transactionAtUtc,
                'updated_at' => $transactionAtUtc,
            ]);

            // 10) Insert items
            foreach ($saleItems as $item) {
                $item['sale_id'] = (string) $sale->id;
                $item['created_at'] = $transactionAtUtc;
                $item['updated_at'] = $transactionAtUtc;
                SaleItem::query()->forceCreate($item);
            }

            // 11) Insert payment (single)
            SalePayment::query()->forceCreate([
                'outlet_id' => $outletId,
                'sale_id' => (string) $sale->id,
                'payment_method_id' => (string) $pm->id,
                'amount' => $paid,
                'reference' => $payment['reference'] ?? null,
                'created_at' => $transactionAtUtc,
                'updated_at' => $transactionAtUtc,
            ]);

            if ($selectedSquadDiscountModel && $selectedSquadUser) {
                app(DiscountSquadService::class)->registerUsage($selectedSquadDiscountModel, $sale, $selectedSquadUser);
            }

            return $sale->load(['items', 'payments', 'customer', 'outlet']);
        });
    }

    public function generateQueueNumber(string $outletId, $transactionMoment = null): string
    {
        $context = $this->resolveSaleSequenceContext($outletId, $transactionMoment);
        $nextCount = $this->resolveNextDailySequence($outletId, $context['today_token'], $context['day_start_utc'], $context['day_end_utc']);

        return str_pad((string) $nextCount, 3, '0', STR_PAD_LEFT);
    }

    private function resolveRequestedQueueNumber(string $outletId, ?string $requestedQueueNo, $transactionMoment = null, ?string $clientSyncId = null): string
    {
        $requested = strtoupper(trim((string) ($requestedQueueNo ?? '')));
        $requested = preg_replace('/[^A-Z0-9-]+/', '', $requested) ?? '';
        $requested = substr($requested, 0, 20);

        if ($requested === '') {
            return $this->generateQueueNumber($outletId, $transactionMoment);
        }

        $exists = Sale::query()
            ->where('outlet_id', $outletId)
            ->where('queue_no', $requested)
            ->lockForUpdate()
            ->exists();

        if (!$exists) {
            return $requested;
        }

        $context = $this->resolveSaleSequenceContext($outletId, $transactionMoment);
        $nextCount = $this->resolveNextDailySequence($outletId, $context['today_token'], $context['day_start_utc'], $context['day_end_utc']);
        $counter = str_pad((string) $nextCount, 3, '0', STR_PAD_LEFT);
        $base = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($clientSyncId ?? '')));
        $suffix = substr($base, 0, 4);
        if ($suffix === '') {
            $suffix = Str::upper(Str::random(4));
        }

        $candidate = sprintf('%s-%s', $counter, str_pad($suffix, 4, 'X'));
        $candidate = substr($candidate, 0, 20);

        $candidateExists = Sale::query()
            ->where('outlet_id', $outletId)
            ->where('queue_no', $candidate)
            ->lockForUpdate()
            ->exists();

        return $candidateExists ? sprintf('%s-%s', $counter, Str::upper(Str::random(4))) : $candidate;
    }

    public function generateSaleNumber(string $outletId, $transactionMoment = null): string
    {
        $context = $this->resolveSaleSequenceContext($outletId, $transactionMoment);
        $nextCount = $this->resolveNextDailySequence($outletId, $context['today_token'], $context['day_start_utc'], $context['day_end_utc']);
        $counter = str_pad((string) $nextCount, 3, '0', STR_PAD_LEFT);
        $random = Str::upper(Str::random(4));

        return sprintf(
            'S.%s-%s-%s-%s',
            $context['outlet_code'],
            $context['today_token'],
            $random,
            $counter
        );
    }


    private function resolveRequestedSaleNumber(string $outletId, ?string $preferredSaleNumber, $transactionMoment = null): string
    {
        $preferred = trim((string) ($preferredSaleNumber ?? ''));
        if ($preferred === '') {
            return $this->generateSaleNumber($outletId, $transactionMoment);
        }

        $context = $this->resolveSaleSequenceContext($outletId, $transactionMoment);
        $pattern = sprintf(
            '/^S\.%s-%s-[A-Z0-9]{4}-\d{3}$/',
            preg_quote($context['outlet_code'], '/'),
            preg_quote($context['today_token'], '/')
        );

        if (!preg_match($pattern, $preferred)) {
            return $this->generateSaleNumber($outletId, $transactionMoment);
        }

        $exists = Sale::query()
            ->where('outlet_id', $outletId)
            ->where('sale_number', $preferred)
            ->lockForUpdate()
            ->exists();

        return $exists ? $this->generateSaleNumber($outletId, $transactionMoment) : $preferred;
    }

    private function resolveSaleSequenceContext(string $outletId, $transactionMoment = null): array
    {
        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->select(['code', 'timezone'])
            ->first();

        $tz = $outletRow?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $nowTz = $transactionMoment instanceof Carbon ? $transactionMoment->copy()->setTimezone($tz) : now($tz);

        return [
            'outlet_code' => strtoupper((string) ($outletRow?->code ?: 'OUT')),
            'timezone' => $tz,
            'today_token' => $nowTz->format('Ymd'),
            'day_start_utc' => $nowTz->copy()->startOfDay()->utc(),
            'day_end_utc' => $nowTz->copy()->endOfDay()->utc(),
        ];
    }

    private function resolveTransactionMoment(?string $value, string $timezone): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->setTimezone($timezone);
            } catch (\Throwable) {
                // fall through
            }
        }

        return now($timezone);
    }

    private function resolveNextDailySequence(string $outletId, string $todayToken, $dayStartUtc, $dayEndUtc): int
    {
        $rows = Sale::query()
            ->where('outlet_id', $outletId)
            ->where(function ($query) use ($todayToken, $dayStartUtc, $dayEndUtc) {
                $query
                    ->where('sale_number', 'like', '%-' . $todayToken . '-%')
                    ->orWhere(function ($legacyScope) use ($dayStartUtc, $dayEndUtc) {
                        $legacyScope
                            ->where(function ($saleNumberScope) {
                                $saleNumberScope
                                    ->whereNull('sale_number')
                                    ->orWhere('sale_number', 'not like', 'S.%-%-%');
                            })
                            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc]);
                    });
            })
            ->lockForUpdate()
            ->get(['sale_number', 'queue_no']);

        $maxSequence = 0;

        foreach ($rows as $row) {
            $saleNumber = (string) ($row->sale_number ?? '');
            if ($saleNumber !== '' && preg_match('/-(\d{3})$/', $saleNumber, $saleMatches)) {
                $maxSequence = max($maxSequence, (int) $saleMatches[1]);
                continue;
            }

            $queueNo = trim((string) ($row->queue_no ?? ''));
            if ($queueNo !== '' && preg_match('/(\d+)$/', $queueNo, $queueMatches)) {
                $maxSequence = max($maxSequence, (int) $queueMatches[1]);
            }
        }

        return $maxSequence + 1;
    }


    public function auditOfflinePayload(string $outletId, array $payload): array
    {
        $payloadChannel = strtoupper(trim((string) ($payload['channel'] ?? '')));
        $clientSyncId = isset($payload['client_sync_id']) ? trim((string) $payload['client_sync_id']) : null;
        $queueNo = trim((string) ($payload['queue_no'] ?? ''));
        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->select(['id', 'code', 'name', 'timezone'])
            ->first();

        $outletTimezone = $outletRow?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $transactionAtInput = isset($payload['transaction_at']) ? trim((string) $payload['transaction_at']) : '';
        $transactionAtTz = $this->resolveTransactionMoment($transactionAtInput !== '' ? $transactionAtInput : null, $outletTimezone);

        $existingSale = null;
        if ($clientSyncId !== null && $clientSyncId !== '') {
            $existingSale = Sale::query()
                ->where('client_sync_id', $clientSyncId)
                ->where('outlet_id', $outletId)
                ->first(['id', 'sale_number', 'queue_no', 'created_at']);
        }

        $paymentMethodId = $payload['payment']['payment_method_id'] ?? null;
        $paymentStrict = null;
        $paymentLoose = null;
        if ($paymentMethodId) {
            $paymentStrict = PaymentMethod::query()
                ->where('id', $paymentMethodId)
                ->where('is_active', true)
                ->whereHas('outlets', function ($q) use ($outletId) {
                    $q->where('outlets.id', $outletId)
                        ->where('outlet_payment_method.is_active', true);
                })
                ->first(['id', 'name', 'type', 'is_active']);

            $paymentLoose = PaymentMethod::query()
                ->where('id', $paymentMethodId)
                ->first(['id', 'name', 'type', 'is_active']);
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $itemAudits = [];
        $summary = [
            'items_total' => count($items),
            'items_missing_snapshot_price' => 0,
            'items_missing_live_price' => 0,
            'items_rescuable_from_live_price' => 0,
        ];

        foreach ($items as $idx => $row) {
            $productId = (string) ($row['product_id'] ?? '');
            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null && $row['variant_id'] !== ''
                ? (string) $row['variant_id']
                : null;
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $itemChannel = strtoupper(trim((string) ($row['channel'] ?? $payloadChannel)));
            $snapshotUnitPrice = is_numeric($row['unit_price_snapshot'] ?? null) ? (int) round((float) $row['unit_price_snapshot']) : null;
            $snapshotLineTotal = is_numeric($row['line_total_snapshot'] ?? null) ? (int) round((float) $row['line_total_snapshot']) : null;
            $hasSnapshotPricing = ($snapshotUnitPrice !== null && $snapshotUnitPrice >= 0)
                || ($snapshotLineTotal !== null && $snapshotLineTotal >= 0);

            $variantResolution = 'provided';
            if (!$variantId && $productId !== '') {
                $variants = ProductVariant::query()
                    ->where('outlet_id', $outletId)
                    ->where('product_id', $productId)
                    ->where('is_active', true)
                    ->get(['id']);
                if ($variants->count() === 1) {
                    $variantId = (string) $variants->first()->id;
                    $variantResolution = 'auto-single-variant';
                } elseif ($variants->count() > 1) {
                    $variantResolution = 'missing-multiple-variants';
                } else {
                    $variantResolution = 'missing-no-variant';
                }
            } elseif (!$variantId) {
                $variantResolution = 'missing-product-and-variant';
            }

            $variant = null;
            if ($variantId) {
                $variant = ProductVariant::query()
                    ->where('outlet_id', $outletId)
                    ->where('id', $variantId)
                    ->with(['product:id,name,category_id', 'product.category:id,name,slug,kind'])
                    ->first();
            }

            $livePriceRow = null;
            if ($variantId && in_array($itemChannel, [SalesChannels::DINE_IN, SalesChannels::TAKEAWAY, SalesChannels::DELIVERY], true)) {
                $livePriceRow = ProductVariantPrice::query()
                    ->where('outlet_id', $outletId)
                    ->where('variant_id', $variantId)
                    ->where('channel', $itemChannel)
                    ->first(['price']);
            }

            $summary['items_missing_snapshot_price'] += $hasSnapshotPricing ? 0 : 1;
            $summary['items_missing_live_price'] += $livePriceRow ? 0 : 1;
            $summary['items_rescuable_from_live_price'] += (!$hasSnapshotPricing && $livePriceRow) ? 1 : 0;

            $itemAudits[] = [
                'index' => $idx,
                'product_id' => $productId !== '' ? $productId : null,
                'variant_id' => $variantId,
                'qty' => $qty,
                'channel' => $itemChannel !== '' ? $itemChannel : null,
                'product_name' => $row['product_name'] ?? ($variant?->product?->name),
                'variant_name' => $row['variant_name'] ?? ($variant?->name),
                'variant_resolution' => $variantResolution,
                'has_snapshot_pricing' => $hasSnapshotPricing,
                'snapshot_unit_price' => $snapshotUnitPrice,
                'snapshot_line_total' => $snapshotLineTotal,
                'live_price_found' => (bool) $livePriceRow,
                'live_unit_price' => $livePriceRow ? (int) $livePriceRow->price : null,
                'rescue_possible' => (!$hasSnapshotPricing && $livePriceRow !== null),
                'category_name' => $row['category_name_snapshot'] ?? ($variant?->product?->category?->name),
                'category_kind' => $row['category_kind_snapshot'] ?? ($variant?->product?->category?->kind),
            ];
        }

        return [
            'outlet' => [
                'id' => (string) $outletId,
                'code' => $outletRow?->code,
                'name' => $outletRow?->name,
                'timezone' => $outletTimezone,
            ],
            'client_sync_id' => $clientSyncId,
            'queue_no' => $queueNo !== '' ? $queueNo : null,
            'transaction_at' => $transactionAtTz->toIso8601String(),
            'existing_sale' => $existingSale ? [
                'id' => (string) $existingSale->id,
                'sale_number' => (string) $existingSale->sale_number,
                'queue_no' => (string) ($existingSale->queue_no ?? ''),
                'created_at' => optional($existingSale->created_at)->toIso8601String(),
            ] : null,
            'payment' => [
                'payment_method_id' => $paymentMethodId ? (string) $paymentMethodId : null,
                'strict_found' => (bool) $paymentStrict,
                'loose_found' => (bool) $paymentLoose,
                'name' => $paymentStrict?->name ?? $paymentLoose?->name,
                'type' => $paymentStrict?->type ?? $paymentLoose?->type,
                'strict_hint' => $paymentStrict ? 'active-in-outlet' : ($paymentLoose ? 'exists-but-disabled-for-outlet' : 'missing'),
            ],
            'summary' => [
                ...$summary,
                'rescue_candidates' => array_values(array_filter(array_map(function ($item) {
                    return $item['rescue_possible'] ? $item['index'] : null;
                }, $itemAudits), fn ($v) => $v !== null)),
            ],
            'items' => $itemAudits,
        ];
    }

    public function rescueOfflinePayload(string $outletId, array $payload): array
    {
        $payloadChannel = strtoupper(trim((string) ($payload['channel'] ?? '')));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        foreach ($items as $idx => $row) {
            $snapshotUnitPrice = is_numeric($row['unit_price_snapshot'] ?? null) ? (int) round((float) $row['unit_price_snapshot']) : null;
            $snapshotLineTotal = is_numeric($row['line_total_snapshot'] ?? null) ? (int) round((float) $row['line_total_snapshot']) : null;
            $hasSnapshotPricing = ($snapshotUnitPrice !== null && $snapshotUnitPrice >= 0)
                || ($snapshotLineTotal !== null && $snapshotLineTotal >= 0);
            if ($hasSnapshotPricing) {
                continue;
            }

            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null && $row['variant_id'] !== ''
                ? (string) $row['variant_id']
                : null;
            $productId = isset($row['product_id']) ? (string) $row['product_id'] : '';
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $itemChannel = strtoupper(trim((string) ($row['channel'] ?? $payloadChannel)));

            if (!$variantId && $productId !== '') {
                $variants = ProductVariant::query()
                    ->where('outlet_id', $outletId)
                    ->where('product_id', $productId)
                    ->where('is_active', true)
                    ->get(['id']);
                if ($variants->count() === 1) {
                    $variantId = (string) $variants->first()->id;
                    $items[$idx]['variant_id'] = $variantId;
                }
            }

            if (!$variantId || !in_array($itemChannel, [SalesChannels::DINE_IN, SalesChannels::TAKEAWAY, SalesChannels::DELIVERY], true)) {
                continue;
            }

            $priceRow = ProductVariantPrice::query()
                ->where('outlet_id', $outletId)
                ->where('variant_id', $variantId)
                ->where('channel', $itemChannel)
                ->first(['price']);

            if (!$priceRow) {
                continue;
            }

            $variant = ProductVariant::query()
                ->where('outlet_id', $outletId)
                ->where('id', $variantId)
                ->with(['product:id,name,category_id', 'product.category:id,name,slug,kind'])
                ->first();

            $unitPrice = (int) $priceRow->price;
            $items[$idx]['unit_price_snapshot'] = $unitPrice;
            $items[$idx]['line_total_snapshot'] = $unitPrice * $qty;
            if (empty($items[$idx]['product_name']) && $variant?->product?->name) {
                $items[$idx]['product_name'] = (string) $variant->product->name;
            }
            if (empty($items[$idx]['variant_name']) && $variant?->name) {
                $items[$idx]['variant_name'] = (string) $variant->name;
            }
            if (empty($items[$idx]['category_id_snapshot']) && $variant?->product?->category_id) {
                $items[$idx]['category_id_snapshot'] = (string) $variant->product->category_id;
            }
            if (empty($items[$idx]['category_kind_snapshot']) && $variant?->product?->category?->kind) {
                $items[$idx]['category_kind_snapshot'] = (string) $variant->product->category->kind;
            }
            if (empty($items[$idx]['category_name_snapshot']) && $variant?->product?->category?->name) {
                $items[$idx]['category_name_snapshot'] = (string) $variant->product->category->name;
            }
            if (empty($items[$idx]['category_slug_snapshot']) && $variant?->product?->category?->slug) {
                $items[$idx]['category_slug_snapshot'] = (string) $variant->product->category->slug;
            }
        }

        $payload['items'] = $items;
        $payload['outlet_id'] = $payload['outlet_id'] ?? $outletId;

        return $payload;
    }

}
