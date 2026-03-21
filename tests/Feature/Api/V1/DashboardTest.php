<?php

namespace Tests\Feature\Api\V1;

use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\User;
use App\Support\SalesChannels;
use Database\Seeders\AuthSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function tokenForCashier(): string
    {
        $this->seed(AuthSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $cashier = User::factory()->create([
            'outlet_id' => $admin->outlet_id,
        ]);
        $cashier->assignRole('cashier');

        $abilities = $cashier->getAllPermissions()->pluck('name')->values()->all();
        return $cashier->createToken('test', $abilities)->plainTextToken;
    }

    private function createSaleViaCheckout(string $token): void
    {
        $cashier = User::query()->whereHas('roles', fn($q) => $q->where('name', 'cashier'))->firstOrFail();

        $pm = PaymentMethod::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->where('is_active', true)
            ->firstOrFail();

        $variant = ProductVariant::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->firstOrFail();

        $price = ProductVariantPrice::query()
            ->where('outlet_id', $cashier->outlet_id)
            ->where('variant_id', $variant->id)
            ->where('channel', SalesChannels::DINE_IN)
            ->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/checkout', [
                'channel' => SalesChannels::DINE_IN,
                'items' => [['variant_id' => (string) $variant->id, 'qty' => 1]],
                'payment' => ['payment_method_id' => (string) $pm->id, 'amount' => (int) $price->price],
            ])
            ->assertStatus(201);
    }

    public function test_dashboard_summary_ok_and_structure(): void
    {
        $token = $this->tokenForCashier();
        $this->createSaleViaCheckout($token);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/dashboard/summary');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'range' => ['date_from', 'date_to', 'status'],
                    'metrics' => ['trx_count', 'gross_sales', 'items_sold', 'avg_ticket'],
                    'by_channel',
                    'by_payment_method',
                    'top_items',
                    'recent_sales',
                ]
            ]);
    }

    public function test_dashboard_summary_respects_recent_limit(): void
    {
        $token = $this->tokenForCashier();
        $this->createSaleViaCheckout($token);
        $this->createSaleViaCheckout($token);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/dashboard/summary?recent_limit=1');

        $res->assertOk()
            ->assertJsonPath('success', true);

        $recent = $res->json('data.recent_sales');
        $this->assertCount(1, $recent);
    }
}
