<?php

namespace Tests\Feature\Patch3;

use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutIgnoresTaxPercentTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_ignores_request_tax_percent_and_uses_default_tax(): void
    {
        $this->seed();

        $tax = Tax::query()->where('is_active', true)->where('is_default', true)->firstOrFail();

        $cashier = User::query()->where('email', 'cashier.a@example.com')->firstOrFail();
        $outletId = (string) $cashier->outlet_id;

        // Grab any variant+price for this outlet (seeded by ProductSeeder)
        $variant = ProductVariant::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->firstOrFail();

        $price = ProductVariantPrice::query()
            ->where('outlet_id', $outletId)
            ->where('variant_id', $variant->id)
            ->where('channel', 'DINE_IN')
            ->firstOrFail();

        // Ensure payment method exists for outlet
        $pmId = \App\Models\PaymentMethod::query()
            ->where('is_active', true)
            ->whereHas('outlets', function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId)->where('outlet_payment_method.is_active', true);
            })
            ->value('id');

        $this->assertNotEmpty($pmId);

        Sanctum::actingAs($cashier);

        $payload = [
            'channel' => 'DINE_IN',
            'bill_name' => 'TEST BILL',
            'tax_percent' => 99, // should be ignored
            'discount' => ['type' => 'NONE', 'value' => 0],
            'items' => [
                [
                    'product_id' => (string) $variant->product_id,
                    'variant_id' => (string) $variant->id,
                    'qty' => 1,
                ],
            ],
            'payment' => [
                'payment_method_id' => (string) $pmId,
                'amount' => ((int) $price->price) + 100000, // cash paid
            ],
        ];

        $resp = $this->postJson('/api/v1/pos/checkout', $payload);
        $resp->assertStatus(201);

        $this->assertSame((int) $tax->percent, $resp->json('data.tax_percent'));

        $expectedTax = (int) floor(((int) $price->price * (int) $tax->percent) / 100);
        $this->assertSame($expectedTax, $resp->json('data.tax_total'));
    }
}
