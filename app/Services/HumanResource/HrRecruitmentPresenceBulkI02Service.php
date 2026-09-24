<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrRecruitmentPresenceBulkI02Service
{
    public function __construct(private readonly HrRecruitmentPresenceI08Service $presence) {}

    public function bulkOpen(Request $request, array $scheduleIds, ?string $note, User $actor): array
    {
        $unique = array_values(array_unique(array_map(fn ($id) => trim((string) $id), $scheduleIds)));

        return DB::transaction(function () use ($request, $unique, $note, $actor): array {
            $items = [];

            // Existing single-open service remains the sole business-rule authority.
            // Wrapping it in one outer transaction makes bulk activation all-or-nothing:
            // scope, stage, existing presence, and schedule eligibility are validated for
            // every selected row before the outer transaction can commit.
            foreach ($unique as $scheduleId) {
                $items[] = $this->presence->openWindow($request, $scheduleId, $note, $actor);
            }

            return [
                'requested_count' => count($unique),
                'processed_count' => count($items),
                'items' => $items,
            ];
        });
    }
}
