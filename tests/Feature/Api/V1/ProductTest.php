<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();
        $abilities = $admin->getAllPermissions()->pluck('name')->values()->all();

        return $admin->createToken('test', $abilities)->plainTextToken;
    }

    public function test_products_index_requires_token(): void
    {
        $res = $this->getJson('/api/v1/products');
        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_can_create_product_with_variants_and_prices(): void
    {
        $token = $this->adminToken();

        $payload = [
            'name' => 'Es Teh',
            'variants' => [
                [
                    'name' => 'Regular',
                    'sku' => 'ESTEH-REG',
                    'prices' => [
                        ['channel' => 'DINE_IN', 'price' => 5000],
                        ['channel' => 'TAKEAWAY', 'price' => 6000],
                        ['channel' => 'DELIVERY', 'price' => 7000],
                    ],
                ],
            ],
        ];

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/products', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Es Teh')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'variants' => [
                        ['id', 'name', 'sku', 'prices']
                    ],
                ],
            ]);
    }

    public function test_admin_can_list_and_filter_products(): void
    {
        $token = $this->adminToken();

        // create 2 products quickly via API (ensures outlet scope)
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/products', [
            'name' => 'Kopi Susu',
            'variants' => [[
                'name' => 'Regular',
                'sku' => 'KS-REG',
                'prices' => [
                    ['channel' => 'DINE_IN', 'price' => 15000],
                    ['channel' => 'TAKEAWAY', 'price' => 16000],
                    ['channel' => 'DELIVERY', 'price' => 18000],
                ],
            ]],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/products', [
            'name' => 'Teh Manis',
            'variants' => [[
                'name' => 'Regular',
                'sku' => 'TM-REG',
                'prices' => [
                    ['channel' => 'DINE_IN', 'price' => 8000],
                    ['channel' => 'TAKEAWAY', 'price' => 9000],
                    ['channel' => 'DELIVERY', 'price' => 10000],
                ],
            ]],
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/products?q=kop&per_page=10&sort=name&dir=asc');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['items', 'pagination'],
            ]);

        $items = $res->json('data.items');
        $this->assertNotEmpty($items);
    }

    public function test_admin_can_update_product_and_variant_prices(): void
    {
        $token = $this->adminToken();

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/products', [
                'name' => 'Jus Jeruk',
                'variants' => [[
                    'name' => 'Regular',
                    'sku' => 'JJ-REG',
                    'prices' => [
                        ['channel' => 'DINE_IN', 'price' => 12000],
                        ['channel' => 'TAKEAWAY', 'price' => 13000],
                        ['channel' => 'DELIVERY', 'price' => 15000],
                    ],
                ]],
            ]);

        $productId = $create->json('data.id');
        $variantId = $create->json('data.variants.0.id');

        // Update: change name + update prices + add new variant, and keep existing with id
        $update = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/products/'.$productId, [
                'name' => 'Jus Jeruk Fresh',
                'variants' => [
                    [
                        'id' => $variantId,
                        'name' => 'Regular',
                        'sku' => 'JJ-REG',
                        'prices' => [
                            ['channel' => 'DINE_IN', 'price' => 12500],
                            ['channel' => 'TAKEAWAY', 'price' => 13500],
                            ['channel' => 'DELIVERY', 'price' => 15500],
                        ],
                    ],
                    [
                        'name' => 'Large',
                        'sku' => 'JJ-LRG',
                        'prices' => [
                            ['channel' => 'DINE_IN', 'price' => 17000],
                            ['channel' => 'TAKEAWAY', 'price' => 18000],
                            ['channel' => 'DELIVERY', 'price' => 20000],
                        ],
                    ],
                ],
            ]);

        $update->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Jus Jeruk Fresh');
    }

    public function test_cashier_cannot_create_product(): void
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $cashier = User::factory()->create();
        $cashier->outlet_id = $admin->outlet_id;
        $cashier->save();
        $cashier->assignRole('cashier');

        $token = $cashier->createToken('test', $cashier->getAllPermissions()->pluck('name')->values()->all())
            ->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/products', [
                'name' => 'Should Fail',
                'variants' => [[
                    'name' => 'Regular',
                    'prices' => [
                        ['channel' => 'DINE_IN', 'price' => 1000],
                    ],
                ]],
            ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_admin_can_delete_product_soft_delete(): void
    {
        $token = $this->adminToken();

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/products', [
                'name' => 'Soda',
                'variants' => [[
                    'name' => 'Regular',
                    'sku' => 'SODA-REG',
                    'prices' => [
                        ['channel' => 'DINE_IN', 'price' => 9000],
                    ],
                ]],
            ]);

        $productId = $create->json('data.id');

        $del = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/products/'.$productId);

        $del->assertOk()->assertJsonPath('success', true);

        $this->assertSoftDeleted('products', ['id' => $productId]);
    }
}
