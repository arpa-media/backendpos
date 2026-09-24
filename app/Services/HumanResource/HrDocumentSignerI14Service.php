<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrDocumentSigner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrDocumentSignerI14Service
{
    public function references(): array
    {
        $employees = DB::table('employees as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('assignments as a', 'a.id', '=', 'e.assignment_id')
            ->orderBy('e.full_name')
            ->get(['e.id','e.user_id','e.nisj','e.full_name','a.role_title','u.name as user_name'])
            ->map(fn ($r) => [
                'key' => 'employee:'.(string)$r->id,
                'source_type' => 'employee',
                'employee_id' => (string)$r->id,
                'user_id' => $r->user_id ? (string)$r->user_id : null,
                'name' => (string)($r->full_name ?: $r->user_name ?: '-'),
                'role' => (string)($r->role_title ?: ''),
                'nisj' => (string)($r->nisj ?: ''),
            ]);

        $employeeUsers = $employees->pluck('user_id')->filter()->all();
        $users = DB::table('users')->where('is_active', true)
            ->when($employeeUsers !== [], fn($q) => $q->whereNotIn('id', $employeeUsers))
            ->orderBy('name')->get(['id','name','email'])
            ->map(fn ($r) => [
                'key' => 'user:'.(string)$r->id,
                'source_type' => 'user',
                'employee_id' => null,
                'user_id' => (string)$r->id,
                'name' => (string)$r->name,
                'role' => '',
                'nisj' => '',
                'email' => (string)($r->email ?: ''),
            ]);

        return [
            'items' => HrDocumentSigner::query()->orderBy('template_key')->orderBy('company_code')->get()->map(fn($r) => $this->payload($r, true))->values()->all(),
            'candidates' => $employees->concat($users)->values()->all(),
            'companies' => [['code'=>'BKJB','name'=>'PT. Bhinneka Karya Jaya Bersama'],['code'=>'MDMF','name'=>'PT. Minuman Dan Makanan Favoritmu']],
        ];
    }

    public function save(array $data, ?UploadedFile $signature, ?User $actor): array
    {
        $templateKey = strtolower(trim((string)$data['template_key']));
        $companyCode = strtoupper(trim((string)$data['company_code']));
        if (!in_array($companyCode, ['BKJB','MDMF'], true)) {
            throw ValidationException::withMessages(['company_code'=>['PT penanda tangan harus BKJB atau MDMF.']]);
        }

        $sourceType = strtolower(trim((string)($data['source_type'] ?? 'custom')));
        $sourceUserId = $data['source_user_id'] ?? null;
        $sourceEmployeeId = $data['source_employee_id'] ?? null;
        $name = trim((string)($data['signer_name'] ?? ''));
        $role = trim((string)($data['signer_role'] ?? ''));

        if ($sourceType === 'employee' && $sourceEmployeeId) {
            $employee = DB::table('employees')->where('id', $sourceEmployeeId)->first();
            if (!$employee) throw ValidationException::withMessages(['source_employee_id'=>['Employee penanda tangan tidak ditemukan.']]);
            $name = $name ?: trim((string)($employee->full_name ?? ''));
            $sourceUserId = $sourceUserId ?: ($employee->user_id ?? null);
            if ($role === '') {
                $role = (string)(DB::table('assignments')->where('id', $employee->assignment_id ?? null)->value('role_title') ?: 'Human Resource Development');
            }
        } elseif ($sourceType === 'user' && $sourceUserId) {
            $user = DB::table('users')->where('id', $sourceUserId)->first();
            if (!$user) throw ValidationException::withMessages(['source_user_id'=>['User penanda tangan tidak ditemukan.']]);
            $name = $name ?: trim((string)$user->name);
        }

        if ($name === '') throw ValidationException::withMessages(['signer_name'=>['Nama penanda tangan wajib diisi.']]);
        if ($role === '') throw ValidationException::withMessages(['signer_role'=>['Jabatan penanda tangan wajib diisi.']]);

        return DB::transaction(function () use ($templateKey,$companyCode,$sourceType,$sourceUserId,$sourceEmployeeId,$name,$role,$signature,$actor): array {
            $row = HrDocumentSigner::query()->where('template_key',$templateKey)->where('company_code',$companyCode)->lockForUpdate()->first();
            if (!$row) $row = new HrDocumentSigner(['id'=>(string)Str::ulid(),'template_key'=>$templateKey,'company_code'=>$companyCode]);

            $row->source_type = in_array($sourceType,['employee','user','custom','default'],true) ? $sourceType : 'custom';
            $row->source_user_id = $sourceUserId ?: null;
            $row->source_employee_id = $sourceEmployeeId ?: null;
            $row->signer_name = $name;
            $row->signer_role = $role;
            $row->is_active = true;
            if (!$row->exists) $row->created_by_user_id = $actor?->id;
            $row->updated_by_user_id = $actor?->id;
            $row->save();

            if ($signature) {
                $ext = strtolower($signature->getClientOriginalExtension() ?: 'png');
                if (!in_array($ext,['png','jpg','jpeg','webp'],true)) $ext = 'png';
                $relative = 'hr/signers/'.strtolower($companyCode).'/'.preg_replace('/[^a-z0-9_-]/i','_', $templateKey).'/'.$row->id.'.'.$ext;
                $absolute = storage_path('app/'.$relative);
                if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0775, true);
                $bytes = file_get_contents($signature->getRealPath());
                if ($bytes === false || file_put_contents($absolute, $bytes) === false) throw ValidationException::withMessages(['signature'=>['Image tanda tangan gagal disimpan.']]);
                $previousPath = trim((string) ($row->signature_path ?? ''));
                if ($previousPath !== '' && $previousPath !== $relative && str_starts_with($previousPath, 'hr/signers/')) {
                    $previousAbsolute = storage_path('app/'.$previousPath);
                    if (is_file($previousAbsolute)) @unlink($previousAbsolute);
                }
                $row->signature_path = $relative;
                $row->signature_mime = $signature->getMimeType() ?: 'image/png';
                $row->signature_sha256 = hash('sha256', $bytes);
                $row->save();
            }

            return $this->payload($row->fresh(), true);
        });
    }

    public function applyToBranding(string $templateKey, ?string $companyCode, array $branding): array
    {
        $company = strtoupper(trim((string)$companyCode));
        if (!in_array($company,['BKJB','MDMF'],true)) return $branding;
        $row = HrDocumentSigner::query()->where('template_key', strtolower(trim($templateKey)))->where('company_code',$company)->where('is_active',true)->first();
        if (!$row) return $branding;
        $branding['signatory_name'] = $row->signer_name;
        $branding['signatory_role'] = $row->signer_role;
        $branding['signer_id'] = (string)$row->id;
        $branding['signer_template_key'] = $row->template_key;
        $branding['signer_company_code'] = $row->company_code;
        $branding['signature_sha256'] = $row->signature_sha256;
        $dataUri = $this->signatureDataUri($row);
        if ($dataUri) $branding['signature_asset'] = $dataUri;
        return $branding;
    }

    public function snapshotFor(string $templateKey, string $companyCode, array $branding): array
    {
        return $this->applyToBranding($templateKey, $companyCode, $branding);
    }

    private function payload(HrDocumentSigner $row, bool $withPreview = false): array
    {
        return [
            'id'=>(string)$row->id,'template_key'=>$row->template_key,'company_code'=>$row->company_code,
            'source_type'=>$row->source_type,'source_user_id'=>$row->source_user_id,'source_employee_id'=>$row->source_employee_id,
            'signer_name'=>$row->signer_name,'signer_role'=>$row->signer_role,'signature_sha256'=>$row->signature_sha256,
            'signature_preview'=>$withPreview ? $this->signatureDataUri($row) : null,'is_active'=>(bool)$row->is_active,
            'updated_at'=>$row->updated_at?->toIso8601String(),
        ];
    }

    private function signatureDataUri(HrDocumentSigner $row): ?string
    {
        $path = trim((string)($row->signature_path ?? ''));
        if ($path === '') return null;
        $absolute = storage_path('app/'.$path);
        if (!is_file($absolute)) return null;
        $bytes = file_get_contents($absolute);
        if ($bytes === false || strlen($bytes) > 2_500_000) return null;
        $mime = trim((string)($row->signature_mime ?: 'image/png'));
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
