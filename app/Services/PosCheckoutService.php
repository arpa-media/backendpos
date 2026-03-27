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
use App\Support\SaleRounding;
use App\Support\SalesChannels;
use App\Support\SaleStatuses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    /**
     * Checkout (atomic).
     *
     * NOTE:
     * - Outlet is resolved by middleware (OutletScope) and passed in from controller.
     * - Tax percent from request is ignored; tax is computed server-side from active default tax.
     */
    public function checkout(User $user, string $outletId, array $payload): Sale
    {
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
            $discountValue,
            $discountReason,
            $discountId,
            $discounts,
            $taxId,
            $taxName,
            $taxPercent,
            $clientSyncId,
            $onlineOrderSource
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

            // 1) Validate payment method: global active + enabled in outlet via pivot
            $pm = PaymentMethod::query()
                ->where('id', $payment['payment_method_id'])
                ->where('is_active', true)
                ->whereHas('outlets', function ($q) use ($outletId) {
                    $q->where('outlets.id', $outletId)
                        ->where('outlet_payment_method.is_active', true);
                })
                ->first();

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

            $variants = ProductVariant::query()
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->whereIn('id', $variantIds)
                ->with(['product', 'product.category'])
                ->get()
                ->keyBy('id');

            if ($variants->count() !== count($variantIds)) {
                throw ValidationException::withMessages([
                    'items' => ['One or more variants not found/disabled for this outlet.'],
                ]);
            }

            // 4) Load prices for required channels (Patch-6: per-item channel)
            $prices = ProductVariantPrice::query()
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

                $priceRow = $prices->get($variantId.'|'.$itemChannel);
                if (!$priceRow) {
                    throw ValidationException::withMessages([
                        "items.$idx.variant_id" => ["Price not found for channel $itemChannel."],
                    ]);
                }

                $unitPrice = (int) $priceRow->price;
                $lineTotal = $unitPrice * $qty;

                $subtotal += $lineTotal;

                $saleItems[] = [
                    'outlet_id' => $outletId,
                    'product_id' => (string) $variant->product_id,
                    'variant_id' => (string) $variant->id,
                    'channel' => $itemChannel,
                    'product_name' => (string) optional($variant->product)->name,
                    'variant_name' => (string) $variant->name,
                    'category_kind_snapshot' => (string) (optional(optional($variant->product)->category)->kind ?? 'OTHER'),
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
            if (count($packageIds) > 0) {
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

            if ($usePackages) {
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
                    $t = strtoupper((string) $pkg->discount_type);
                    $v = (int) $pkg->discount_value;
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
            $discountPackage = null;
            if ($usePackages) {
                $discountPackage = $discountPackages->first();
                // For backward compatibility fields, keep the first package spec.
                $discountType = $discountPackage ? strtoupper((string) $discountPackage->discount_type) : 'NONE';
                $discountValue = $discountPackage ? (int) $discountPackage->discount_value : 0;
                $codes = collect($discountSnapshots)->pluck('code')->filter()->values()->all();
                $discountReason = !empty($codes) ? implode('+', $codes) : null;
            }

            // 7) Tax (default tax per outlet, except online/delivery which are always no-tax)
            $isOnlineNoTax = $saleChannel === SalesChannels::DELIVERY;
            $effectiveTaxId = $isOnlineNoTax ? null : $taxId;
            $effectiveTaxName = $isOnlineNoTax ? 'Tax' : $taxName;
            $effectiveTaxPercent = $isOnlineNoTax ? 0 : (int) $taxPercent;

            $taxTotal = (int) floor(($taxableBase * $effectiveTaxPercent) / 100);
            $serviceChargeTotal = 0;

            $roundingSnapshot = SaleRounding::apply((int) ($taxableBase + $taxTotal + $serviceChargeTotal));
            $roundingTotal = (int) ($roundingSnapshot['rounding_total'] ?? 0);
            $grandTotalBeforeRounding = (int) ($roundingSnapshot['before_rounding'] ?? 0);
            $grandTotal = (int) ($roundingSnapshot['after_rounding'] ?? 0);
            $marking = app(MarkingService::class)->determineNextMarking($outletId);

            // 8) Payment rule
            $inputPaid = (int) ($payment['amount'] ?? 0);
            if ((string) $pm->type !== PaymentMethodTypes::CASH) {
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
            $sale = Sale::query()->create([
                'outlet_id' => $outletId,
                'cashier_id' => (string) $user->id,
                'cashier_name' => (string) ($user->name ?? ''),

                'client_sync_id' => $clientSyncId ?: null,
                'sale_number' => $this->generateSaleNumber($outletId),
                'queue_no' => $queueNo !== '' ? $queueNo : $this->generateQueueNumber($outletId),
                'channel' => (string) $saleChannel,
                'online_order_source' => (string) $onlineOrderSource,
                'status' => SaleStatuses::PAID,

                'bill_name' => (string) $billName,
                'customer_id' => $customer ? (string) $customer->id : null,
                'table_chamber' => $tableChamber !== '' ? $tableChamber : null,
                'table_number' => $tableNumber !== '' ? $tableNumber : null,

                'subtotal' => $subtotal,

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
                'discounts_snapshot' => $usePackages ? $discountSnapshots : null,

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
                'payment_method_name' => (string) ($pm->name ?? ''),
                'payment_method_type' => (string) ($pm->type ?? ''),

                'note' => $payload['note'] ?? null,
            ]);

            // 10) Insert items
            foreach ($saleItems as $item) {
                $item['sale_id'] = (string) $sale->id;
                SaleItem::query()->create($item);
            }

            // 11) Insert payment (single)
            SalePayment::query()->create([
                'outlet_id' => $outletId,
                'sale_id' => (string) $sale->id,
                'payment_method_id' => (string) $pm->id,
                'amount' => $paid,
                'reference' => $payment['reference'] ?? null,
            ]);

            return $sale->load(['items', 'payments', 'customer', 'outlet']);
        });
    }

    public function generateQueueNumber(string $outletId): string
    {
        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->select(['timezone'])
            ->first();

        $tz = $outletRow?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $nowTz = now($tz);
        $dayStartUtc = $nowTz->copy()->startOfDay()->utc();
        $dayEndUtc = $nowTz->copy()->endOfDay()->utc();

        $lastSale = Sale::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first(['queue_no', 'sale_number']);

        $nextCount = 1;
        $lastQueue = trim((string) ($lastSale?->queue_no ?? ''));
        if ($lastQueue !== '' && preg_match('/(\d+)$/', $lastQueue, $m)) {
            $nextCount = ((int) $m[1]) + 1;
        } elseif ($lastSale && preg_match('/-(\d{3})$/', (string) $lastSale->sale_number, $m)) {
            $nextCount = ((int) $m[1]) + 1;
        }

        return str_pad((string) $nextCount, 3, '0', STR_PAD_LEFT);
    }

    public function generateSaleNumber(string $outletId): string
    {
        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->select(['code', 'timezone'])
            ->first();

        $tz = $outletRow?->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $nowTz = now($tz);
        $today = $nowTz->format('Ymd');

        $outletCode = $outletRow?->code;

        $outletCode = strtoupper($outletCode ?? 'OUT');

        // lock for concurrency
        // Compare "today" in outlet timezone.
        // created_at is stored in UTC; build UTC range for the local day.
        $dayStartUtc = $nowTz->copy()->startOfDay()->utc();
        $dayEndUtc = $nowTz->copy()->endOfDay()->utc();

        $lastSale = Sale::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        $nextCount = 1;
        if ($lastSale && preg_match('/-(\d{3})$/', (string) $lastSale->sale_number, $m)) {
            $nextCount = ((int) $m[1]) + 1;
        }

        $counter = str_pad((string) $nextCount, 3, '0', STR_PAD_LEFT);
        $random = Str::upper(Str::random(4));

        return sprintf(
            'S.%s-%s-%s-%s',
            $outletCode,
            $today,
            $random,
            $counter
        );
    }
}
