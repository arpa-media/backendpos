<?php

namespace Tests\Feature\Api\V1;

use Database\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success_returns_token_and_user(): void
    {
        $this->seed(AuthSeeder::class);

        $res = $this->postJson('/api/v1/auth/login', [
            'nisj' => config('pos.seed_admin.nisj'),
            'password' => config('pos.seed_admin.password'),
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'abilities',
                    'user' => ['id', 'name', 'nisj', 'email', 'outlet', 'roles', 'permissions'],
                ],
            ]);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->seed(AuthSeeder::class);

        $res = $this->getJson('/api/v1/auth/me');

        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_permission_forbidden_returns_403(): void
    {
        $this->seed(AuthSeeder::class);

        $user = \App\Models\User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        // Beri role cashier (tidak punya admin.access)
        $user->assignRole('cashier');

        $token = $user->createToken('test', $user->getAllPermissions()->pluck('name')->values()->all());

        $res = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/admin/ping');

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }
}
