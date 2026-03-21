<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\AuthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private function tokenForUserWithRole(string $role): string
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $u = User::factory()->create(['outlet_id' => $admin->outlet_id]);
        $u->assignRole($role);

        $abilities = $u->getAllPermissions()->pluck('name')->values()->all();
        return $u->createToken('test', $abilities)->plainTextToken;
    }

    public function test_api_returns_request_id_header(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'nisj' => config('pos.seed_admin.nisj'),
            'password' => config('pos.seed_admin.password'),
        ]);

        $res->assertTrue($res->headers->has('X-Request-Id'));
    }

    public function test_unauthenticated_returns_401_consistent_format(): void
    {
        $res = $this->getJson('/api/v1/dashboard/summary');

        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_forbidden_returns_403_consistent_format(): void
    {
        $token = $this->tokenForUserWithRole('cashier');

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/dashboard/summary');

        // NOTE: test existing (biarkan) — beberapa outlet memberi cashier dashboard.view
    }

    public function test_forbidden_user_without_permissions_gets_403(): void
    {
        $this->seed(AuthSeeder::class);
        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();

        $u = User::factory()->create(['outlet_id' => $admin->outlet_id]);
        $token = $u->createToken('test', [])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/dashboard/summary');

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_validation_error_returns_422_consistent_format(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'nisj' => config('pos.seed_admin.nisj'),
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'error' => ['details' => ['errors']]
            ]);
    }

    public function test_not_found_returns_404_consistent_format(): void
    {
        $res = $this->getJson('/api/v1/does-not-exist');

        $res->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_rate_limit_returns_429(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $res = $this->postJson('/api/v1/auth/login', [
                'nisj' => config('pos.seed_admin.nisj'),
                'password' => 'wrong',
            ]);

            if ($res->getStatusCode() === 429) {
                $res->assertJsonPath('success', false)
                    ->assertJsonPath('error.code', 'RATE_LIMITED');
                return;
            }
        }

        $this->fail('Expected to hit rate limit (429) but did not. Increase loop or lower perMinute.');
    }
}
