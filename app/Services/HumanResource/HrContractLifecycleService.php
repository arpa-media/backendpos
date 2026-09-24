<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrContract;
use App\Models\HumanResource\HrContractDocument;
use App\Models\HumanResource\HrContractReminder;

class HrContractLifecycleService
{
    public function __construct(
        private readonly HrContractDocumentService $documents,
        private readonly HrContractService $contracts,
    ) {}

    public function sweep(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $today = now()->toDateString();
        $effects = 0;
        HrContractDocument::query()
            ->where('status', 'approved')->where('effect_status', 'pending_effective')
            ->whereNotNull('effective_date')->whereDate('effective_date', '<=', $today)
            ->orderBy('effective_date')->orderBy('created_at')->limit($limit)->get()->each(function ($doc) use (&$effects): void {
                if ($this->documents->applyEffect($doc, null)) $effects++;
            });

        $expired = 0;
        HrContract::query()->where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<', $today)
            ->orderBy('end_date')->limit($limit)->get()->each(function ($contract) use (&$expired): void {
                $before = $this->contracts->snapshot($contract);
                $contract->update(['status' => 'expired']);
                $this->contracts->event($contract, 'contract_expired', $contract->end_date?->toDateString(), 'Kontrak melewati tanggal berakhir dan ditandai expired otomatis.', $before, $this->contracts->snapshot($contract), null);
                $expired++;
            });

        $reminders = 0;
        HrContractReminder::query()->where('status', 'pending')->whereDate('remind_on', '<=', $today)
            ->orderBy('remind_on')->limit($limit)->get()->each(function ($reminder) use (&$reminders): void {
                $reminder->update(['status' => 'due', 'triggered_at' => now()]);
                $contract = HrContract::query()->find($reminder->contract_id);
                if ($contract) $this->contracts->event($contract, 'reminder_due', $reminder->remind_on?->toDateString(), $reminder->label, null, $reminder->fresh()->toArray(), null);
                $reminders++;
            });

        return ['effects_applied' => $effects, 'contracts_expired' => $expired, 'reminders_due' => $reminders];
    }
}
