<?php

namespace Database\Seeders;

use App\Support\Auth\ManualAssignmentOverrideApplier;
use Illuminate\Database\Seeder;

class ManualAssignmentOverrideSeeder extends Seeder
{
    public function run(): void
    {
        app(ManualAssignmentOverrideApplier::class)->sync();
    }
}
