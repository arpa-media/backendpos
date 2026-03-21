<?php

namespace Tests\Feature\Patch3;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutletScopeLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_spoof_outlet_scope_via_header(): void
    {
        $this->seed();

        $cashier = User::query()->where('email', 'cashier.a@example.com')->firstOrFail();
        $lockedOutletId = (string) $cashier->outlet_id;

        $otherOutlet = Outlet::query()->where('id', '!=', $lockedOutletId)->firstOrFail();

        Sanctum::actingAs($cashier);

        // Attempt to spoof scope
        $resp = $this->withHeader('X-Outlet-Id', (string) $otherOutlet->id)
            ->getJson('/api/v1/outlet');

        $resp->assertStatus(200);
        $this->assertSame($lockedOutletId, $resp->json('data.id'));
    }
}
