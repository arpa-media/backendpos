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

class SalesHistoryTest extends TestCase
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

    private function createSaleViaCheckout(string $token): array
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

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/checkout', [
                'channel' => SalesChannels::DINE_IN,
                'items' => [['variant_id' => (string) $variant->id, 'qty' => 1]],
                'payment' => ['payment_method_id' => (string) $pm->id, 'amount' => (int) $price->price],
                'note' => 'test note',
            ]);

        $res->assertStatus(201);

        return [
            'sale_id' => $res->json('data.id'),
            'sale_number' => $res->json('data.sale_number'),
        ];
    }

    public function test_sales_list_returns_items_and_pagination(): void
    {
        $token = $this->tokenForCashier();
        $this->createSaleViaCheckout($token);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sales?per_page=10&sort=created_at&dir=desc');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        ['id','sale_number','channel','status','grand_total','paid_total','change_total','items_count','created_at']
                    ],
                    'pagination' => ['current_page','per_page','total','last_page']
                ]
            ]);
    }

    public function test_sales_list_can_filter_by_q(): void
    {
        $token = $this->tokenForCashier();
        $created = $this->createSaleViaCheckout($token);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sales?q='.$created['sale_number'].'&per_page=10');

        $res->assertOk()
            ->assertJsonPath('success', true);

        $items = $res->json('data.items');
        $this->assertNotEmpty($items);
        $this->assertEquals($created['sale_number'], $items[0]['sale_number']);
    }

    public function test_sales_show_returns_detail_with_items_payments_and_snapshots(): void
    {
        $token = $this->tokenForCashier();
        $created = $this->createSaleViaCheckout($token);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sales/'.$created['sale_id']);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $created['sale_id'])
            ->assertJsonStructure([
                'data' => [
                    'sale_number',
                    'cashier_id',
                    'cashier_name',
                    'payment_method_name',
                    'payment_method_type',
                    'items' => [['variant_id','qty','unit_price','line_total']],
                    'payments' => [['payment_method_id','amount']]
                ]
            ]);

        $this->assertNotEmpty($res->json('data.cashier_name'));
        $this->assertNotEmpty($res->json('data.payment_method_name'));
    }
}
