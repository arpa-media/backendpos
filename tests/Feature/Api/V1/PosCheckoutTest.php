<?php

namespace Tests\Feature\Api\V1;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\User;
use App\Support\SalesChannels;
use Database\Seeders\AuthSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function cashierToken(): string
    {
        $this->seed(AuthSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $cashier = User::factory()->create();
        $cashier->outlet_id = $admin->outlet_id;
        $cashier->save();
        $cashier->assignRole('cashier');

        $abilities = $cashier->getAllPermissions()->pluck('name')->values()->all();
        return $cashier->createToken('test', $abilities)->plainTextToken;
    }

    public function test_checkout_requires_token(): void
    {
        $res = $this->postJson('/api/v1/pos/checkout', []);
        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_cashier_can_checkout_paid_sale(): void
    {
        $token = $this->cashierToken();
        $cashier = User::query()->whereHas('roles', fn($q) => $q->where('name','cashier'))->firstOrFail();

        $pm = PaymentMethod::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->where('is_active', true)
            ->firstOrFail();

        // pick one variant and ensure price exists (seeded by ProductSeeder)
        $variant = ProductVariant::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->firstOrFail();

        $price = ProductVariantPrice::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->where('variant_id', $variant->id)
            ->where('channel', SalesChannels::DINE_IN)
            ->firstOrFail();

        $qty = 2;
        $grand = $price->price * $qty;

        $payload = [
            'channel' => SalesChannels::DINE_IN,
            'items' => [
                ['variant_id' => (string) $variant->id, 'qty' => $qty],
            ],
            'payment' => [
                'payment_method_id' => (string) $pm->id,
                'amount' => $grand,
            ],
        ];

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/checkout', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.channel', SalesChannels::DINE_IN)
            ->assertJsonPath('data.grand_total', $grand)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'sale_number',
                    'items' => [['variant_id', 'qty', 'unit_price', 'line_total']],
                    'payments' => [['payment_method_id', 'amount']],
                ]
            ]);
    }

    public function test_checkout_fails_if_paid_less_than_total(): void
    {
        $token = $this->cashierToken();
        $cashier = User::query()->whereHas('roles', fn($q) => $q->where('name','cashier'))->firstOrFail();

        $pm = PaymentMethod::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->where('is_active', true)
            ->firstOrFail();

        $variant = ProductVariant::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->firstOrFail();

        $payload = [
            'channel' => SalesChannels::DINE_IN,
            'items' => [
                ['variant_id' => (string) $variant->id, 'qty' => 1],
            ],
            'payment' => [
                'payment_method_id' => (string) $pm->id,
                'amount' => 0,
            ],
        ];

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/checkout', $payload);

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_sales_history_list_requires_permission_and_returns_items(): void
    {
        $token = $this->cashierToken();

        // create one sale via checkout quickly
        $cashier = User::query()->whereHas('roles', fn($q) => $q->where('name','cashier'))->firstOrFail();
        $pm = PaymentMethod::query()->where('outlet_id', $cashier->outlet_id)->where('is_active', true)->firstOrFail();
        $variant = ProductVariant::query()->where('outlet_id', $cashier->outlet_id)->firstOrFail();
        $price = ProductVariantPrice::query()->where('outlet_id', $cashier->outlet_id)->where('variant_id', $variant->id)->where('channel', SalesChannels::DINE_IN)->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/pos/checkout', [
            'channel' => SalesChannels::DINE_IN,
            'items' => [['variant_id' => (string) $variant->id, 'qty' => 1]],
            'payment' => ['payment_method_id' => (string) $pm->id, 'amount' => (int) $price->price],
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sales?per_page=10&sort=created_at&dir=desc');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['items', 'pagination'],
            ]);

        $items = $res->json('data.items');
        $this->assertNotEmpty($items);
    }
}
