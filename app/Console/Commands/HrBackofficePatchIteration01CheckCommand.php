<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration01CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i01-check';
    protected $description = 'Verify HR Backoffice Patch I01: searchable Squad, Uniform reversal, duplicate-name filter, and Access Matrix.';

    public function handle(): int
    {
        $frontend = base_path('../frontend - Backoffice');
        $punishment = $this->source($frontend.'/src/pages/human-resource/HumanResourcePunishmentPage.vue');
        $uniform = $this->source($frontend.'/src/pages/human-resource/HumanResourceUniformOutboundI11Page.vue');
        $squad = $this->source($frontend.'/src/pages/human-resource/HumanResourceSquadPage.vue');
        $squadController = $this->source(app_path('Http/Controllers/Api/V1/HumanResource/HrSquadController.php'));
        $uniformRoute = $this->source(base_path('routes/hr_modules/36-uniform-outbound-i11.php'));
        $uniformService = $this->source(app_path('Services/HumanResource/HrUniformOutboundI11Service.php'));
        $inventory = $this->source(app_path('Services/HumanResource/HrUniformInventoryI10Service.php'));

        $checks = [
            'Searchable Squad component exists' => is_file($frontend.'/src/components/human-resource/HrSearchableSquadSelect.vue'),
            'Punishment uses searchable Squad' => substr_count($punishment, 'HrSearchableSquadSelect') >= 4,
            'Uniform Outbound uses searchable Squad' => str_contains($uniform, 'HrSearchableSquadSelect'),
            'Uniform cancel action is Access Matrix guarded' => str_contains($uniform, "canMenuActionByAccess(auth.access, accessPath.value, 'update', 'human-resource')"),
            'Uniform cancel API route is guarded' => str_contains($uniformRoute, 'permission_or_snapshot:hr.uniform.outbound.cancel'),
            'Uniform cancellation reverses inventory' => str_contains($uniformService, 'reverseOutboundMovement(') && str_contains($inventory, "'UNIFORM_I11_REVERSAL'"),
            'Uniform cancellation blocks finalized payroll' => str_contains($uniformService, "status === 'SETTLED'") || str_contains($uniformService, "\$status === 'SETTLED'"),
            'Data Squad exact duplicate filter UI' => str_contains($squad, 'exact_duplicate_name'),
            'Data Squad exact duplicate filter backend' => str_contains($squadController, "BINARY TRIM(`exact_duplicate_squad`.`full_name`)") && str_contains($squadController, "request->boolean('exact_duplicate_name')"),
            'Uniform cancellation audit columns' => $this->uniformCancelColumnsReady(),
            'Uniform cancel permission exists' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.uniform.outbound.cancel')->exists(),
            'Uniform menu update permission mapped' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-uniform-outbound')->where('permission_update', 'hr.uniform.outbound.cancel')->exists(),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<info>[INFO]</info> Pembatalan Uniform hanya untuk status POSTED. Potongan SETTLED/final wajib reopen payroll lebih dulu.');
        $this->line('<info>[INFO]</info> Role/level yang sebelumnya boleh Create Uniform Keluar diberi default Edit=ON agar tombol reversal langsung tersedia dan tetap bisa dimatikan dari Access Matrix.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function source(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function uniformCancelColumnsReady(): bool
    {
        if (! Schema::hasTable('HR_uniform_outbounds')) return false;
        return Schema::hasColumn('HR_uniform_outbounds', 'cancelled_at')
            && Schema::hasColumn('HR_uniform_outbounds', 'cancelled_by_user_id')
            && Schema::hasColumn('HR_uniform_outbounds', 'cancel_reason');
    }
}
