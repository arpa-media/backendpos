<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ERP POS FINAL I06
 *
 * Canonical privacy boundary for Purchasing applicant-owned documents.
 * Finance and Administrator may see all records. Every other user only sees
 * documents whose canonical Fund Request was created by that same user.
 *
 * Keep this rule in backend query scopes; frontend filtering is never a
 * security boundary.
 */
class PurchasingOwnershipScopeService
{
    public function __construct(private readonly OrderApprovalPolicy $approvalPolicy)
    {
    }

    public function canViewAll(User $user): bool
    {
        return $this->approvalPolicy->isAdministrator($user)
            || $this->approvalPolicy->isFinanceActor($user);
    }

    public function scopeFundRequests(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        return $query->where('created_by_user_id', (string) $user->id);
    }

    public function scopeOrders(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        return $query->whereIn(
            'fund_request_id',
            FundRequest::query()
                ->where('created_by_user_id', (string) $user->id)
                ->select('id')
        );
    }

    /** @return array{mode:string,label:string,can_view_all:bool} */
    public function descriptor(User $user): array
    {
        $canViewAll = $this->canViewAll($user);

        return [
            'mode' => $canViewAll ? 'ALL' : 'OWN',
            'label' => $canViewAll ? 'Semua Pemohon' : 'Permohonan Saya',
            'can_view_all' => $canViewAll,
        ];
    }
}
