<?php

namespace Tests\Feature\Patch3;

use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxDefaultInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_default_unsets_other_defaults_atomically(): void
    {
        $this->seed();

        $admin = User::query()->where('email', config('pos.seed_admin.email'))->firstOrFail();
        Sanctum::actingAs($admin);

        $t1 = Tax::query()->create([
            'jenis_pajak' => 'TAX_A',
            'display_name' => 'Tax A',
            'percent' => 10,
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ]);

        $t2 = Tax::query()->create([
            'jenis_pajak' => 'TAX_B',
            'display_name' => 'Tax B',
            'percent' => 11,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 2,
        ]);

        $resp = $this->postJson("/api/v1/taxes/{$t2->id}/set-default");
        $resp->assertStatus(200);

        $this->assertTrue(Tax::query()->findOrFail($t2->id)->is_default);
        $this->assertFalse(Tax::query()->findOrFail($t1->id)->is_default);

        $defaults = Tax::query()->where('is_active', true)->where('is_default', true)->count();
        $this->assertSame(1, $defaults);
    }
}
