<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\HumanResource\HrContract;
use App\Models\HumanResource\HrContractApproval;
use App\Models\HumanResource\HrContractDocument;
use App\Models\HumanResource\HrContractDocumentTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrContractDocumentService
{
    public function __construct(
        private readonly HrContractService $contracts,
        private readonly HrDocumentBrandingService $branding,
        private readonly HrDocumentNumberService $numbers,
        private readonly HrDocumentTemplateCatalog $catalog,
        private readonly HrDocumentSignerI14Service $signers,
    ) {}

    public function templates(): array
    {
        $definitions = $this->catalog->definitions();
        $definitions = array_merge($this->contractTemplateDefinitions(), $definitions);
        $keys = array_keys($definitions);

        return HrContractDocumentTemplate::query()
            ->whereIn('document_type', $keys)
            ->orderBy('document_type')
            ->orderByDesc('version')
            ->get()
            ->map(function ($row) use ($definitions): array {
                $definition = $definitions[$row->document_type] ?? [];
                $company = $definition['company_code'] ?? null;
                return [
                    'id' => (string) $row->id,
                    'template_key' => $row->document_type,
                    'document_type' => $definition['business_type'] ?? $row->document_type,
                    'business_type' => $definition['business_type'] ?? $row->document_type,
                    'name' => $row->name,
                    'version' => (int) $row->version,
                    'title_template' => $row->title_template,
                    'body_template' => $row->body_template,
                    'is_active' => (bool) $row->is_active,
                    'company_code' => $company,
                    'letter_code' => $definition['letter_code'] ?? null,
                    'layout_key' => $definition['layout_key'] ?? 'letter',
                    'source_name' => $definition['source_name'] ?? 'Database template',
                    'compatible_contract_type' => $definition['compatible_contract_type'] ?? null,
                    'branding' => $company ? $this->signers->snapshotFor($row->document_type, $company, $this->branding->branding($company)) : null,
                    'display_order' => $definition['display_order'] ?? 999,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $row) => sprintf('%04d-%s-%05d', $row['display_order'], $row['template_key'], 99999 - $row['version']))
            ->values()
            ->all();
    }

    public function createTemplateVersion(array $data, ?User $actor): array
    {
        $templateKey = trim((string) ($data['template_key'] ?? ''));
        $definition = $this->templateDefinition($templateKey);

        return DB::transaction(function () use ($data, $actor, $templateKey, $definition): array {
            $next = ((int) HrContractDocumentTemplate::query()->where('document_type', $templateKey)->max('version')) + 1;
            HrContractDocumentTemplate::query()->where('document_type', $templateKey)->where('is_active', true)->update(['is_active' => false]);
            $row = HrContractDocumentTemplate::query()->create([
                'document_type' => $templateKey,
                'name' => $definition['name'],
                'version' => $next,
                'title_template' => trim((string) $data['title_template']),
                'body_template' => trim((string) $data['body_template']),
                'is_active' => true,
                'created_by_user_id' => $actor?->id,
            ]);

            return [
                'id' => (string) $row->id,
                'template_key' => $templateKey,
                'document_type' => $definition['business_type'],
                'name' => $definition['name'],
                'version' => $next,
                'company_code' => $definition['company_code'],
                'letter_code' => $definition['letter_code'],
                'is_active' => true,
            ];
        });
    }

    public function activateTemplate(string $id): bool
    {
        $row = HrContractDocumentTemplate::query()->find($id);
        $allowedKeys = array_merge(array_keys($this->contractTemplateDefinitions()), $this->catalog->keys());
        if (! $row || ! in_array($row->document_type, $allowedKeys, true)) return false;

        DB::transaction(function () use ($row): void {
            HrContractDocumentTemplate::query()->where('document_type', $row->document_type)->update(['is_active' => false]);
            $row->update(['is_active' => true]);
        });

        return true;
    }

    public function generate(string $contractId, array $data, ?User $actor): ?array
    {
        $contract = HrContract::query()->with(['employee', 'outlet', 'assignment.outlet'])->find($contractId);
        if (! $contract) return null;

        $type = strtolower(trim((string) $data['document_type']));
        $this->validateEffectPayload($type, $data, $contract);
        $canonical = in_array($type, ['promotion', 'transfer', 'demotion'], true);
        $templateKey = null;
        $definition = null;
        $companyCode = null;
        $brandingSnapshot = null;

        if ($canonical) {
            $companyCode = $this->branding->resolveCompanyCode(
                $data['company_code'] ?? null,
                $data['new_outlet_id'] ?? $contract->outlet_id,
            );
            $templateKey = trim((string) ($data['template_key'] ?? '')) ?: $this->catalog->keyFor($type, $companyCode);
            $definition = $this->catalog->definitionFor($templateKey);
            if ($definition['business_type'] !== $type || ($definition['company_code'] && $definition['company_code'] !== $companyCode)) {
                throw ValidationException::withMessages(['template_key' => ['Template yang dipilih tidak sesuai jenis SK/PT.']]);
            }
            $template = HrContractDocumentTemplate::query()
                ->where('document_type', $templateKey)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->first();
            if (! $template) throw ValidationException::withMessages(['template_key' => ['Template canonical aktif belum tersedia. Jalankan migration Iterasi 02.']]);
            $brandingSnapshot = $this->branding->snapshot($companyCode);
        } else {
            $templateKey = trim((string) ($data['template_key'] ?? '')) ?: $type;
            $definition = $this->templateDefinition($templateKey);
            if (($definition['business_type'] ?? $templateKey) !== $type) {
                throw ValidationException::withMessages(['template_id' => ['Template aktif yang dipilih tidak sesuai jenis dokumen.']]);
            }
            $templateQuery = HrContractDocumentTemplate::query()->where('document_type', $templateKey)->where('is_active', true);
            if (filled($data['template_id'] ?? null)) $templateQuery->whereKey($data['template_id']);
            $template = $templateQuery->orderByDesc('version')->first();
            if (! $template || $template->document_type !== $templateKey) {
                throw ValidationException::withMessages(['template_id' => ['Template aktif yang dipilih tidak sesuai jenis dokumen.']]);
            }
            $compatibleType = strtoupper(trim((string) ($definition['compatible_contract_type'] ?? '')));
            $actualContractType = strtoupper(trim((string) ($contract->contract_type ?? '')));
            if ($type === 'contract' && $compatibleType !== '' && $actualContractType !== $compatibleType) {
                throw ValidationException::withMessages(['template_id' => ['Template '.$template->name.' hanya untuk jenis kontrak '.$compatibleType.'.']]);
            }
            $companyCode = $this->branding->resolveCompanyCode($data['company_code'] ?? null, $contract->outlet_id);
            $brandingSnapshot = $this->branding->snapshot($companyCode);
        }

        if ($brandingSnapshot && $templateKey && $companyCode) {
            $brandingSnapshot = $this->signers->snapshotFor($templateKey, $companyCode, $brandingSnapshot);
        }

        return DB::transaction(function () use ($contract, $data, $actor, $type, $template, $canonical, $templateKey, $definition, $companyCode, $brandingSnapshot): array {
            $issueDate = $data['issue_date'] ?? now()->toDateString();
            $snapshot = $this->mergeSnapshot($contract, $data, $companyCode, $brandingSnapshot);
            $title = $this->render($template->title_template, $snapshot);
            $body = $this->render($template->body_template, $snapshot);
            $effectiveDate = $data['effective_date'] ?? $contract->tmt_date?->toDateString() ?? $contract->start_date?->toDateString();
            $effectStatus = in_array($type, ['contract', 'extension', 'promotion', 'transfer', 'demotion', 'termination'], true) ? 'pending_approval' : 'not_applicable';
            $documentNo = ($companyCode && filled($definition['letter_code'] ?? null))
                ? $this->numbers->allocate((string) $definition['letter_code'], (string) $companyCode, $issueDate)
                : $this->documentNo($type);

            $doc = HrContractDocument::query()->create([
                'contract_id' => $contract->id,
                'template_id' => $template->id,
                'template_key' => $templateKey,
                'company_code' => $companyCode,
                'letter_code' => $definition['letter_code'] ?? null,
                'document_type' => $type,
                'document_no' => $documentNo,
                'title' => $title,
                'issue_date' => $issueDate,
                'effective_date' => $effectiveDate,
                'status' => 'draft',
                'template_version' => $template->version,
                'body_snapshot' => $body,
                'payload_snapshot' => $snapshot,
                'branding_snapshot' => $brandingSnapshot,
                'effect_status' => $effectStatus,
                'generated_by_user_id' => $actor?->id,
            ]);
            $this->contracts->event($contract, 'document_generated', $effectiveDate, 'Draft '.$title.' dibuat.', null, [
                'document_id' => (string) $doc->id,
                'document_type' => $type,
                'document_no' => $doc->document_no,
                'template_key' => $templateKey,
                'company_code' => $companyCode,
            ], $actor, $doc->id);

            return $this->contracts->show($contract->id) ?? [];
        });
    }

    public function submit(string $documentId, ?User $actor): ?array
    {
        $doc = HrContractDocument::query()->find($documentId);
        if (! $doc) return null;
        if ($doc->status !== 'draft' && $doc->status !== 'rejected') throw ValidationException::withMessages(['document' => ['Hanya draft/rejected yang dapat diajukan.']]);
        return DB::transaction(function () use ($doc, $actor) {
            $doc->update([
                'status' => 'submitted', 'submitted_by_user_id' => $actor?->id, 'submitted_at' => now(),
                'rejected_by_user_id' => null, 'rejected_at' => null, 'rejection_note' => null, 'effect_status' => 'pending_approval',
            ]);
            HrContractApproval::query()->updateOrCreate(['document_id' => $doc->id, 'step_number' => 1], [
                'contract_id' => $doc->contract_id, 'status' => 'pending', 'requested_by_user_id' => $actor?->id,
                'requested_at' => now(), 'approver_user_id' => null, 'decided_at' => null, 'note' => null,
            ]);
            $contract = HrContract::query()->find($doc->contract_id);
            if ($contract) $this->contracts->event($contract, 'document_submitted', $doc->effective_date?->toDateString(), 'SK '.$doc->document_no.' diajukan untuk approval.', null, ['document_id' => (string) $doc->id], $actor, $doc->id);
            return $contract ? $this->contracts->show($contract->id) : null;
        });
    }

    public function approve(string $documentId, ?User $actor, ?string $note = null): ?array
    {
        $doc = HrContractDocument::query()->find($documentId);
        if (! $doc) return null;
        if ($doc->status !== 'submitted') throw ValidationException::withMessages(['document' => ['Dokumen harus berstatus submitted sebelum approve.']]);
        return DB::transaction(function () use ($doc, $actor, $note) {
            $doc->update(['status' => 'approved', 'approved_by_user_id' => $actor?->id, 'approved_at' => now()]);
            HrContractApproval::query()->where('document_id', $doc->id)->where('step_number', 1)->update([
                'status' => 'approved', 'approver_user_id' => $actor?->id, 'decided_at' => now(), 'note' => $note,
            ]);
            $contract = HrContract::query()->findOrFail($doc->contract_id);
            $this->contracts->event($contract, 'document_approved', $doc->effective_date?->toDateString(), 'SK '.$doc->document_no.' disetujui.'.($note ? ' '.$note : ''), null, ['document_id' => (string) $doc->id], $actor, $doc->id);
            $effective = $doc->effective_date?->toDateString();
            if (! $effective || $effective <= now()->toDateString()) $this->applyEffect($doc->fresh(), $actor);
            else $doc->update(['effect_status' => 'pending_effective']);
            return $this->contracts->show($contract->id);
        });
    }

    public function reject(string $documentId, ?User $actor, string $note): ?array
    {
        $doc = HrContractDocument::query()->find($documentId);
        if (! $doc) return null;
        if ($doc->status !== 'submitted') throw ValidationException::withMessages(['document' => ['Dokumen harus berstatus submitted sebelum reject.']]);
        return DB::transaction(function () use ($doc, $actor, $note) {
            $doc->update([
                'status' => 'rejected', 'rejected_by_user_id' => $actor?->id, 'rejected_at' => now(),
                'rejection_note' => trim($note), 'effect_status' => 'pending_approval',
            ]);
            HrContractApproval::query()->where('document_id', $doc->id)->where('step_number', 1)->update([
                'status' => 'rejected', 'approver_user_id' => $actor?->id, 'decided_at' => now(), 'note' => trim($note),
            ]);
            $contract = HrContract::query()->find($doc->contract_id);
            if ($contract) $this->contracts->event($contract, 'document_rejected', $doc->effective_date?->toDateString(), 'SK '.$doc->document_no.' ditolak. '.$note, null, ['document_id' => (string) $doc->id], $actor, $doc->id);
            return $contract ? $this->contracts->show($contract->id) : null;
        });
    }

    public function applyEffect(HrContractDocument $document, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($document, $actor): bool {
            // Serialize all effective-dated changes for one contract and re-read
            // the SK under lock so retries from the scheduler remain idempotent.
            $contract = HrContract::query()->whereKey($document->contract_id)->lockForUpdate()->first();
            if (! $contract) return false;

            $doc = HrContractDocument::query()->whereKey($document->id)->lockForUpdate()->first();
            if (! $doc || $doc->status !== 'approved' || $doc->effect_status === 'applied') return false;

            $snapshot = is_array($doc->payload_snapshot) ? $doc->payload_snapshot : [];
            $effective = $doc->effective_date?->toDateString() ?: now()->toDateString();
            if ($effective > now()->toDateString()) {
                $doc->update(['effect_status' => 'pending_effective']);
                return false;
            }

            $before = $this->contracts->snapshot($contract);

            if ($doc->document_type === 'contract') {
                $contract->status = 'active';
                if (! $contract->start_date) $contract->start_date = $effective;
                if (! $contract->tmt_date) $contract->tmt_date = $effective;
                $contract->save();
                $this->ensureAssignment($contract, $effective, $snapshot, $actor);
            } elseif ($doc->document_type === 'extension') {
                $newEnd = $snapshot['new_end_date_raw'] ?? null;
                if (! $newEnd) throw ValidationException::withMessages(['new_end_date' => ['Tanggal akhir baru tidak ada pada snapshot SK.']]);
                $contract->end_date = $newEnd;
                $contract->status = 'active';
                $contract->save();
                if ($contract->assignment_id) {
                    $assignment = Assignment::query()->find($contract->assignment_id);
                    if ($assignment) {
                        // Eloquent save intentionally triggers Assignment model events,
                        // preserving Assignment History for extension changes.
                        $assignment->end_date = $newEnd;
                        $assignment->save();
                    }
                }
            } elseif (in_array($doc->document_type, ['promotion', 'transfer', 'demotion'], true)) {
                $this->transitionAssignment($contract, $effective, $snapshot, $actor);
                if ($doc->document_type === 'demotion' && $contract->squad_id && Schema::hasColumn('HR_squads', 'basic_salary')) {
                    $newSalary = $snapshot['new_salary_raw'] ?? null;
                    if ($newSalary !== null && is_numeric($newSalary)) {
                        DB::table('HR_squads')->where('id', $contract->squad_id)->update(['basic_salary' => (float) $newSalary, 'updated_at' => now()]);
                    }
                }
            } elseif ($doc->document_type === 'termination') {
                $this->terminate($contract, $effective, $snapshot, $actor);
            }

            $contract->refresh();
            $this->contracts->syncLegacy($contract);
            $this->contracts->syncDefaultReminders($contract);
            $doc->update(['effect_status' => 'applied', 'effect_applied_at' => now()]);
            $this->contracts->event(
                $contract,
                'document_effect_applied',
                $effective,
                'Efek bisnis '.$doc->document_no.' diterapkan.',
                $before,
                $this->contracts->snapshot($contract),
                $actor,
                $doc->id,
                $contract->assignment_id,
            );
            return true;
        });
    }

    private function ensureAssignment(HrContract $contract, string $effective, array $snapshot, ?User $actor): void
    {
        if (! $contract->employee_id || $contract->assignment_id) return;
        if (! $contract->outlet_id && ! $contract->position_name) return;
        $assignment = Assignment::query()->create([
            'employee_id' => $contract->employee_id, 'outlet_id' => $contract->outlet_id,
            'role_title' => $contract->position_name, 'start_date' => $effective, 'end_date' => $contract->end_date?->toDateString(),
            'is_primary' => true, 'status' => 'active', 'hr_assignment_id' => null,
        ]);
        Employee::query()->where('id', $contract->employee_id)->update(['assignment_id' => $assignment->id, 'updated_at' => now()]);
        $contract->assignment_id = $assignment->id;
        $contract->save();
    }

    private function transitionAssignment(HrContract $contract, string $effective, array $snapshot, ?User $actor): void
    {
        if (! $contract->employee_id) throw ValidationException::withMessages(['employee' => ['Contract belum terhubung ke employee; assignment tidak dapat dipindahkan.']]);
        $old = Assignment::query()->where('employee_id', $contract->employee_id)->where('is_primary', true)->orderByDesc('start_date')->first();
        if ($old) {
            $end = Carbon::parse($effective)->subDay()->toDateString();
            $old->end_date = $end;
            $old->is_primary = false;
            $old->status = 'inactive';
            $old->save();
        }
        $newOutlet = $snapshot['new_outlet_id'] ?? null;
        $newPosition = trim((string) ($snapshot['new_position'] ?? '')) ?: $contract->position_name;
        $assignment = Assignment::query()->create([
            'employee_id' => $contract->employee_id, 'outlet_id' => $newOutlet,
            'role_title' => $newPosition, 'start_date' => $effective, 'end_date' => $contract->end_date?->toDateString(),
            'is_primary' => true, 'status' => 'active', 'hr_assignment_id' => null,
        ]);
        Employee::query()->where('id', $contract->employee_id)->update(['assignment_id' => $assignment->id, 'updated_at' => now()]);
        $contract->assignment_id = $assignment->id;
        $contract->outlet_id = $newOutlet;
        $contract->assignment_label = $this->assignmentLabel($snapshot['new_assignment'] ?? null, $newOutlet, $contract->assignment_label);
        $contract->division_name = trim((string) ($snapshot['new_division'] ?? '')) ?: $contract->division_name;
        $contract->position_name = $newPosition;
        $contract->status = 'active';
        $contract->save();
    }

    private function terminate(HrContract $contract, string $effective, array $snapshot, ?User $actor): void
    {
        if ($contract->employee_id) {
            $assignments = Assignment::query()->where('employee_id', $contract->employee_id)->where('is_primary', true)->get();
            foreach ($assignments as $assignment) {
                $assignment->end_date = $effective;
                $assignment->is_primary = false;
                $assignment->status = 'inactive';
                $assignment->save();
            }
            Employee::query()->where('id', $contract->employee_id)->update(['assignment_id' => null, 'employment_status' => 'inactive', 'updated_at' => now()]);
        }
        $contract->end_date = $effective;
        $contract->status = 'terminated';
        $contract->assignment_id = null;
        $contract->notes = trim(($contract->notes ? $contract->notes."\n" : '').'Termination: '.($snapshot['reason'] ?? '')); 
        $contract->save();
        if ($contract->squad_id) {
            $squad = DB::table('HR_squads')->where('id', $contract->squad_id)->first();
            DB::table('HR_squads')->where('id', $contract->squad_id)->update(['status' => 'inactive', 'contract_end_date' => $effective, 'updated_at' => now()]);
            if ($squad?->user_id) {
                DB::table('users')->where('id', $squad->user_id)->update(['is_active' => false, 'updated_at' => now()]);
                if (Schema::hasTable('personal_access_tokens')) DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $squad->user_id)->delete();
            }
        }
    }

    private function mergeSnapshot(HrContract $contract, array $data, ?string $companyCode = null, ?array $brandingSnapshot = null): array
    {
        $squad = $contract->squad_id ? DB::table('HR_squads')->where('id', $contract->squad_id)->first() : null;
        $outlet = $contract->outlet_id ? DB::table('outlets')->where('id', $contract->outlet_id)->first() : null;
        $newOutlet = filled($data['new_outlet_id'] ?? null) ? DB::table('outlets')->where('id', $data['new_outlet_id'])->first() : null;
        $endClause = $contract->end_date ? ' sampai dengan '.$this->humanDate($contract->end_date) : ' tanpa tanggal akhir yang ditetapkan pada dokumen ini';
        $branding = $brandingSnapshot ?: [];
        $newSalary = $data['new_salary'] ?? null;

        return [
            'full_name' => $squad?->full_name ?: $contract->employee?->full_name ?: '-',
            'nisj' => $squad?->nisj ?: $contract->employee?->nisj ?: '-',
            'contract_no' => $contract->contract_no ?: '-',
            'contract_type' => $contract->contract_type ?: '-',
            'start_date' => $this->humanDate($contract->start_date),
            'end_date' => $this->humanDate($contract->end_date),
            'end_clause' => $endClause,
            'tmt_date' => $this->humanDate($contract->tmt_date ?: $contract->start_date),
            'first_sk_date' => $this->humanDate($contract->first_sk_date),
            'assignment' => $contract->assignment_label ?: $outlet?->name ?: '-',
            'division' => $contract->division_name ?: $squad?->division_name ?: '-',
            'position' => $contract->position_name ?: $squad?->position_name ?: '-',
            'current_outlet_id' => $contract->outlet_id,
            'current_outlet_name' => $outlet?->name ?: $contract->assignment?->outlet?->name ?: '-',
            'current_outlet_address' => $outlet?->address ?: '-',
            'effective_date' => $this->humanDate($data['effective_date'] ?? $contract->tmt_date ?? $contract->start_date),
            'new_end_date' => $this->humanDate($data['new_end_date'] ?? null),
            'new_end_date_raw' => $data['new_end_date'] ?? null,
            'new_outlet_id' => $data['new_outlet_id'] ?? null,
            'new_outlet_name' => $newOutlet?->name ?: '-',
            'new_outlet_address' => $newOutlet?->address ?: '-',
            'new_assignment' => $this->assignmentLabel($data['new_assignment'] ?? null, $data['new_outlet_id'] ?? null, $contract->assignment_label),
            'new_division' => trim((string) ($data['new_division'] ?? '')) ?: '-',
            'new_position' => trim((string) ($data['new_position'] ?? '')) ?: '-',
            'new_salary_raw' => $newSalary !== null && $newSalary !== '' ? (float) $newSalary : null,
            'new_salary_formatted' => $newSalary !== null && $newSalary !== '' ? 'Rp '.number_format((float) $newSalary, 0, ',', '.') : '-',
            'reason' => trim((string) ($data['reason'] ?? '')) ?: '-',
            'issue_date' => $this->humanDate($data['issue_date'] ?? now()),
            'company_code' => $companyCode,
            'company_name' => $branding['company_name'] ?? '-',
            'company_address' => $branding['address'] ?? '-',
            'company_phone' => $branding['phone'] ?? '-',
            'company_email' => $branding['email'] ?? '-',
            'branding' => $branding,
            'logo_asset' => $branding['logo_asset'] ?? '/hr/logo-hr.png',
            'signature_asset' => $branding['signature_asset'] ?? '/hr/signature-ray.jpg',
            'signatory_name' => $branding['signatory_name'] ?? 'Ray Fanany Muhammad',
            'signatory_role' => $branding['signatory_role'] ?? 'Human Resource Development',
        ];
    }

    private function assignmentLabel(mixed $value, mixed $outletId = null, mixed $fallback = null): string
    {
        if ($outletId) {
            $type = strtolower(trim((string) DB::table('outlets')->where('id', $outletId)->value('type')));
            if ($type === 'warehouse') return 'WAREHOUSE';
            if (in_array($type, ['headquarter', 'management'], true)) return 'MANAGEMENT';
            if ($type === 'outlet') return 'OUTLET';
        }
        foreach ([$value, $fallback] as $candidate) {
            $label = strtoupper(trim((string) ($candidate ?? '')));
            if (in_array($label, ['OUTLET', 'MANAGEMENT', 'WAREHOUSE'], true)) return $label;
            if (str_contains($label, 'WAREHOUSE')) return 'WAREHOUSE';
            if (str_contains($label, 'MANAGEMENT') || str_contains($label, 'HEADQUARTER') || $label === 'HQ') return 'MANAGEMENT';
        }
        return 'OUTLET';
    }

    private function validateEffectPayload(string $type, array $data, HrContract $contract): void
    {
        if ($type === 'extension') {
            $newEnd = $data['new_end_date'] ?? null;
            if (! $newEnd) throw ValidationException::withMessages(['new_end_date' => ['Tanggal berakhir baru wajib untuk SK perpanjangan.']]);
            if ($contract->end_date && Carbon::parse($newEnd)->lte($contract->end_date)) throw ValidationException::withMessages(['new_end_date' => ['Tanggal akhir baru harus setelah tanggal akhir kontrak saat ini.']]);
        }
        if (in_array($type, ['promotion', 'transfer', 'demotion'], true)) {
            if ($contract->start_date && filled($data['effective_date'] ?? null) && Carbon::parse($data['effective_date'])->lt($contract->start_date)) throw ValidationException::withMessages(['effective_date' => ['TMT perubahan penugasan tidak boleh sebelum tanggal mulai kontrak.']]);
            if (! filled($data['new_outlet_id'] ?? null)) throw ValidationException::withMessages(['new_outlet_id' => ['Penugasan/outlet baru wajib dipilih.']]);
            if (! filled($data['new_position'] ?? null)) throw ValidationException::withMessages(['new_position' => ['Jabatan baru wajib diisi.']]);
            if (! filled($data['effective_date'] ?? null)) throw ValidationException::withMessages(['effective_date' => ['TMT promosi/mutasi/demosi wajib diisi.']]);
            if ($type === 'demotion' && (! isset($data['new_salary']) || ! is_numeric($data['new_salary']) || (float) $data['new_salary'] <= 0)) {
                throw ValidationException::withMessages(['new_salary' => ['Nominal gaji baru wajib diisi untuk SK Demosi.']]);
            }
        }
        if ($type === 'termination') {
            if ($contract->start_date && filled($data['effective_date'] ?? null) && Carbon::parse($data['effective_date'])->lt($contract->start_date)) throw ValidationException::withMessages(['effective_date' => ['Tanggal pemutusan tidak boleh sebelum tanggal mulai kontrak.']]);
            if (! filled($data['effective_date'] ?? null)) throw ValidationException::withMessages(['effective_date' => ['Tanggal efektif pemutusan wajib diisi.']]);
            if (! filled($data['reason'] ?? null)) throw ValidationException::withMessages(['reason' => ['Alasan pemutusan wajib diisi.']]);
        }
    }

    private function contractTemplateDefinitions(): array
    {
        return [
            'contract' => ['name'=>'SK Kontrak Kerja','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Template Contract existing','display_order'=>1,'compatible_contract_type'=>null],
            'contract_spt' => ['name'=>'SK Kontrak Kerja - SPT','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle SPT','display_order'=>2,'compatible_contract_type'=>'SPT'],
            'contract_pkwt1' => ['name'=>'SK Kontrak Kerja - PKWT1','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWT1','display_order'=>3,'compatible_contract_type'=>'PKWT1'],
            'contract_pkwt2' => ['name'=>'SK Kontrak Kerja - PKWT2','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWT2','display_order'=>4,'compatible_contract_type'=>'PKWT2'],
            'contract_pkwt3' => ['name'=>'SK Kontrak Kerja - PKWT3','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWT3','display_order'=>5,'compatible_contract_type'=>'PKWT3'],
            'contract_pkwt4' => ['name'=>'SK Kontrak Kerja - PKWT4','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWT4','display_order'=>6,'compatible_contract_type'=>'PKWT4'],
            'contract_pkwt5' => ['name'=>'SK Kontrak Kerja - PKWT5','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWT5','display_order'=>7,'compatible_contract_type'=>'PKWT5'],
            'contract_pkwtt' => ['name'=>'SK Kontrak Kerja - PKWTT','business_type'=>'contract','company_code'=>null,'letter_code'=>'CTR','layout_key'=>'letter','source_name'=>'Turunan SK Kontrak Kerja untuk lifecycle PKWTT','display_order'=>8,'compatible_contract_type'=>'PKWTT'],
            'extension' => ['name'=>'SK Perpanjangan Kontrak','business_type'=>'extension','company_code'=>null,'letter_code'=>'EXT','layout_key'=>'letter','source_name'=>'Template Contract existing','display_order'=>20,'compatible_contract_type'=>null],
            'termination' => ['name'=>'SK Pemutusan Hubungan Kerja','business_type'=>'termination','company_code'=>null,'letter_code'=>'PHK','layout_key'=>'letter','source_name'=>'Template Contract existing','display_order'=>30,'compatible_contract_type'=>null],
        ];
    }

    private function templateDefinition(string $templateKey): array
    {
        $contractDefinitions = $this->contractTemplateDefinitions();
        if (isset($contractDefinitions[$templateKey])) return $contractDefinitions[$templateKey];
        return $this->catalog->definitionFor($templateKey);
    }

    private function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', fn ($m) => (string) ($values[$m[1]] ?? '-'), $template) ?? $template;
    }

    private function documentNo(string $type): string
    {
        $prefix = ['contract' => 'CTR', 'extension' => 'EXT', 'promotion' => 'PRO', 'transfer' => 'MUT', 'demotion' => 'DMO', 'termination' => 'PHK'][$type] ?? 'SK';
        return 'SK-'.$prefix.'-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function humanDate(mixed $value): string
    {
        if (! $value) return '-';
        try { return Carbon::parse($value)->locale('id')->translatedFormat('d F Y'); } catch (\Throwable) { return (string) $value; }
    }
}
