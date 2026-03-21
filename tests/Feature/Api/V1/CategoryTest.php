<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();
        $abilities = $admin->getAllPermissions()->pluck('name')->values()->all();

        return $admin->createToken('test', $abilities)->plainTextToken;
    }

    public function test_index_requires_token(): void
    {
        $res = $this->getJson('/api/v1/categories');
        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_can_create_and_list_categories_with_pagination(): void
    {
        $token = $this->adminToken();

        // create
        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/categories', [
                'name' => 'Dessert',
                'sort_order' => 10,
            ]);

        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Dessert');

        // list
        $list = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/categories?per_page=10&sort=name&dir=asc');

        $list->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_admin_can_update_and_delete_category(): void
    {
        $token = $this->adminToken();
        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $cat = Category::factory()->create([
            'outlet_id' => $admin->outlet_id,
            'name' => 'Old Name',
            'slug' => 'old-name',
            'sort_order' => 1,
        ]);

        $update = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/categories/'.$cat->id, [
                'name' => 'New Name',
                'slug' => 'new-name',
            ]);

        $update->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name');

        $del = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/categories/'.$cat->id);

        $del->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('categories', ['id' => $cat->id]);
    }

    public function test_cashier_cannot_create_category(): void
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
            ->postJson('/api/v1/categories', [
                'name' => 'Should Fail',
            ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_filter_q_works(): void
    {
        $token = $this->adminToken();
        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        Category::factory()->create([
            'outlet_id' => $admin->outlet_id,
            'name' => 'Kopi',
            'slug' => 'kopi',
        ]);
        Category::factory()->create([
            'outlet_id' => $admin->outlet_id,
            'name' => 'Teh',
            'slug' => 'teh',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/categories?q=kop');

        $res->assertOk()
            ->assertJsonPath('success', true);

        $items = $res->json('data.items');
        $this->assertNotEmpty($items);
    }
}
