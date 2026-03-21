<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_outlet_requires_token(): void
    {
        $this->seed(AuthSeeder::class);

        $res = $this->getJson('/api/v1/outlet');

        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_can_view_outlet(): void
    {
        $this->seed(AuthSeeder::class);

        /** @var \App\Models\User $admin */
        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $token = $admin->createToken('test', $admin->getAllPermissions()->pluck('name')->values()->all());

        $res = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/outlet');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'address', 'phone', 'timezone', 'created_at', 'updated_at',
                ],
            ]);
    }

    public function test_cashier_cannot_update_outlet(): void
    {
        $this->seed(AuthSeeder::class);

        $cashier = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        // attach outlet_id from seeded admin outlet
        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();
        $cashier->outlet_id = $admin->outlet_id;
        $cashier->save();

        $cashier->assignRole('cashier');

        $token = $cashier->createToken('test', $cashier->getAllPermissions()->pluck('name')->values()->all());

        $res = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/outlet', [
                'name' => 'Outlet Baru',
                'timezone' => 'Asia/Jakarta',
            ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_admin_can_update_outlet(): void
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $token = $admin->createToken('test', $admin->getAllPermissions()->pluck('name')->values()->all());

        $res = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->putJson('/api/v1/outlet', [
                'name' => 'Outlet Utama Updated',
                'address' => 'Jl. Update No. 2',
                'phone' => '08123456789',
                'timezone' => 'Asia/Jakarta',
            ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Outlet Utama Updated');
    }
}
