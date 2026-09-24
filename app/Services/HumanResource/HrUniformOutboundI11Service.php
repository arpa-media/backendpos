<?php

namespace App\Services\HumanResource;

use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class HrUniformOutboundI11Service
{
    private const OUTBOUNDS = 'HR_uniform_outbounds';
    private const LINES = 'HR_uniform_outbound_lines';
    private const DEDUCTIONS = 'HR_uniform_payroll_deductions';

    public function __construct(
        private readonly HrUniformInventoryI10Service $inventory,
        private readonly HrUniformPayrollDeductionI11Service $payrollDeductions,
        private readonly HrPayrollService $payroll,
    ) {}

    public function references(): array
    {
        $this->assertReady();
        $items = DB::table('HR_uniform_items as i')
            ->leftJoin('HR_uniform_stock_balances as b', 'b.uniform_item_id', '=', 'i.id')
            ->where('i.is_active', true)
            ->orderByRaw("CASE WHEN i.company_code = 'MDMF' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(i.source_row,9999)')
            ->get(['i.id','i.code','i.name','i.company_code','i.item_kind','i.size',DB::raw('COALESCE(b.current_qty,0) as current_qty')])
            ->map(fn ($r) => [
                'id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,
                'company_code'=>(string)$r->company_code,'item_kind'=>(string)$r->item_kind,'size'=>$r->size,
                'current_qty'=>(int)$r->current_qty,
            ])->values()->all();

        $employeeQuery = DB::table('employees as e');
        $this->applyActiveSquadEmployeeFilter($employeeQuery);
        $employeeQuery
            ->leftJoin('assignments as a', function ($join): void {
                $join->on('a.employee_id','=','e.id')->where('a.is_primary',true)->where(function ($q): void {
                    $q->whereNull('a.status')->orWhereNotIn(DB::raw('LOWER(a.status)'), ['inactive','ended']);
                });
            })
            ->leftJoin('outlets as o','o.id','=','a.outlet_id')
            ->select(['e.id','e.nisj','e.full_name','a.outlet_id','a.role_title','o.name as outlet_name'])
            ->orderBy('e.full_name');
        $employees = $employeeQuery->get()->unique('id')->map(fn ($r) => [
            'id'=>(string)$r->id,'nisj'=>$r->nisj,'full_name'=>(string)$r->full_name,
            'outlet_id'=>$r->outlet_id,'outlet_name'=>$r->outlet_name ?: '-', 'position'=>$r->role_title ?: '-',
        ])->values()->all();

        $outlets = DB::table('outlets')->orderBy('name')->get(['id','name'])->map(fn($r)=>['id'=>(string)$r->id,'name'=>(string)$r->name])->all();
        return ['items'=>$items,'employees'=>$employees,'outlets'=>$outlets];
    }

    public function index(array $filters): array
    {
        $this->assertReady();
        $type = strtoupper((string)($filters['outbound_type'] ?? 'UNIFORM'));
        $q = DB::table(self::OUTBOUNDS.' as h')
            ->leftJoin('employees as e','e.id','=','h.employee_id')
            ->leftJoin('users as u','u.id','=','h.created_by_user_id')
            ->where('h.outbound_type',$type)
            ->when(!empty($filters['date_from']), fn($x)=>$x->whereDate('h.outbound_date','>=',$filters['date_from']))
            ->when(!empty($filters['date_to']), fn($x)=>$x->whereDate('h.outbound_date','<=',$filters['date_to']))
            ->when(!empty($filters['status']), fn($x)=>$x->where('h.status',$filters['status']))
            ->when(!empty($filters['search']), function($x) use ($filters): void {
                $s=trim((string)$filters['search']);
                $x->where(function($qq) use($s): void { $qq->where('h.document_no','like',"%{$s}%")->orWhere('h.recipient_name','like',"%{$s}%")->orWhere('h.outlet_name_snapshot','like',"%{$s}%"); });
            })
            ->orderByDesc('h.outbound_date')->orderByDesc('h.created_at')
            ->select(['h.*','e.nisj','u.name as input_by_name']);
        $per=max(10,min(100,(int)($filters['per_page']??25)));
        $p=$q->paginate($per);
        $ids=collect($p->items())->pluck('id')->all();
        $stats=$this->lineStats($ids);
        return $this->formatPaginator($p,function($r)use($stats){
            $s=$stats[(string)$r->id]??['line_count'=>0,'total_qty'=>0,'squad_total'=>0,'company_total'=>0,'manual_total'=>0];
            return ['id'=>(string)$r->id,'document_no'=>(string)$r->document_no,'outbound_type'=>(string)$r->outbound_type,'outbound_date'=>(string)$r->outbound_date,
                'employee_id'=>$r->employee_id,'nisj'=>$r->nisj,'recipient_name'=>(string)$r->recipient_name,'outlet_name'=>$r->outlet_name_snapshot ?: '-',
                'company_code'=>$r->company_code,'payroll_month'=>$r->payroll_month,'notes'=>$r->notes,'status'=>(string)$r->status,'cancel_reason'=>$r->cancel_reason ?? null,'cancelled_at'=>$r->cancelled_at ?? null,'input_by_name'=>$r->input_by_name ?: '-',...$s];
        });
    }

    public function show(string $id): array
    {
        $h=DB::table(self::OUTBOUNDS.' as h')->leftJoin('employees as e','e.id','=','h.employee_id')->where('h.id',$id)->select(['h.*','e.nisj'])->first();
        if(!$h) throw new DomainException('Barang Keluar tidak ditemukan.');
        $lines=DB::table(self::LINES.' as l')->join('HR_uniform_items as i','i.id','=','l.uniform_item_id')
            ->leftJoin(self::DEDUCTIONS.' as d','d.outbound_line_id','=','l.id')->where('l.outbound_id',$id)->orderByRaw('COALESCE(i.source_row,9999)')
            ->get(['l.*','i.code','i.name','i.item_kind','i.size','d.id as deduction_id','d.amount as deduction_amount','d.status as deduction_status','d.payroll_cutoff_id','d.payroll_slip_id','d.settled_at'])
            ->map(fn($r)=>[
                'id'=>(string)$r->id,'uniform_item_id'=>(string)$r->uniform_item_id,'code'=>(string)$r->code,'name'=>(string)$r->name,'item_kind'=>(string)$r->item_kind,'size'=>$r->size,
                'quantity'=>(int)$r->quantity,'purchase_price'=>(float)$r->purchase_price,'squad_charge'=>(float)$r->squad_charge,'size_charge'=>(float)$r->size_charge,'company_charge'=>(float)$r->company_charge,'manual_price'=>(float)$r->manual_price,
                'deduction_amount'=>(float)($r->deduction_amount??0),'deduction_status'=>$this->deductionLabel($r->deduction_status),'payroll_cutoff_id'=>$r->payroll_cutoff_id,'payroll_slip_id'=>$r->payroll_slip_id,'settled_at'=>$r->settled_at,
            ])->all();
        return ['id'=>(string)$h->id,'document_no'=>(string)$h->document_no,'outbound_type'=>(string)$h->outbound_type,'outbound_date'=>(string)$h->outbound_date,'employee_id'=>$h->employee_id,'nisj'=>$h->nisj,'recipient_name'=>(string)$h->recipient_name,'outlet_id'=>$h->outlet_id,'outlet_name'=>$h->outlet_name_snapshot,'company_code'=>$h->company_code,'payroll_month'=>$h->payroll_month,'notes'=>$h->notes,'status'=>(string)$h->status,'cancel_reason'=>$h->cancel_reason ?? null,'cancelled_at'=>$h->cancelled_at ?? null,'cancelled_by_user_id'=>$h->cancelled_by_user_id ?? null,'lines'=>$lines];
    }

    public function create(array $payload, ?string $actorUserId): array
    {
        $this->assertReady();
        $type=strtoupper((string)$payload['outbound_type']);
        if(!in_array($type,['UNIFORM','ATTRIBUTE'],true)) throw new DomainException('Tipe Barang Keluar tidak valid.');
        $idem=trim((string)($payload['idempotency_key']??''));
        if($idem!=='' && ($x=DB::table(self::OUTBOUNDS)->where('idempotency_key',$idem)->first())) return $this->show((string)$x->id);

        return DB::transaction(function() use($payload,$actorUserId,$type,$idem): array {
            if($idem!=='' && ($x=DB::table(self::OUTBOUNDS)->where('idempotency_key',$idem)->lockForUpdate()->first())) return $this->show((string)$x->id);
            $employee=null;
            if($type==='UNIFORM') {
                $employee=DB::table('employees')->where('id',(string)$payload['employee_id'])->first(['id','user_id','nisj','full_name']);
                if(!$employee || !$this->employeeHasActiveSquad($employee)) throw new DomainException('Squad tidak ditemukan, sudah dihapus, atau sudah tidak aktif pada Data Squad.');
            }
            $id=(string)Str::ulid(); $date=(string)$payload['outbound_date']; $doc='UNI-OUT-'.Carbon::parse($date)->format('Ymd').'-'.strtoupper(substr($id,-6));
            $recipient=$type==='UNIFORM'?(string)$employee->full_name:trim((string)$payload['recipient_name']);
            DB::table(self::OUTBOUNDS)->insert([
                'id'=>$id,'document_no'=>$doc,'idempotency_key'=>$idem!==''?$idem:null,'outbound_type'=>$type,'outbound_date'=>$date,
                'employee_id'=>$type==='UNIFORM'?(string)$employee->id:null,'recipient_name'=>$recipient,'outlet_id'=>$payload['outlet_id']??null,
                'outlet_name_snapshot'=>$this->outletName($payload['outlet_id']??null),'company_code'=>strtoupper((string)($payload['company_code']??'')) ?: null,
                'payroll_month'=>$type==='UNIFORM'?(string)$payload['payroll_month']:null,'notes'=>isset($payload['notes'])?trim((string)$payload['notes']):null,
                'status'=>'POSTED','created_by_user_id'=>$actorUserId,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($payload['items'] as $line) {
                $item=DB::table('HR_uniform_items')->where('id',(string)$line['uniform_item_id'])->where('is_active',true)->first();
                if(!$item) throw new DomainException('Item Uniform/Atribut tidak ditemukan atau nonaktif.');
                if($type==='UNIFORM' && strtoupper((string)$item->item_kind)!=='UNIFORM') throw new DomainException('Uniform Keluar hanya menerima item jenis Seragam.');
                if($type==='ATTRIBUTE' && strtoupper((string)$item->item_kind)!=='ATTRIBUTE') throw new DomainException('Atribut Keluar hanya menerima item jenis Atribut.');
                $qty=(int)$line['quantity']; if($qty<=0) throw new DomainException('Jumlah Barang Keluar harus lebih dari 0.');
                $lineId=(string)Str::ulid();
                $purchase=(float)($line['purchase_price']??0); $squad=$type==='UNIFORM'?(float)($line['squad_charge']??0):0.0;
                $sizeCharge=$type==='UNIFORM'?(float)($line['size_charge']??0):0.0; $company=$type==='UNIFORM'?(float)($line['company_charge']??0):0.0;
                $manual=$type==='ATTRIBUTE'?(float)($line['manual_price']??0):0.0;
                DB::table(self::LINES)->insert(['id'=>$lineId,'outbound_id'=>$id,'uniform_item_id'=>(string)$item->id,'quantity'=>$qty,'purchase_price'=>$purchase,'squad_charge'=>$squad,'size_charge'=>$sizeCharge,'company_charge'=>$company,'manual_price'=>$manual,'company_code'=>(string)$item->company_code,'created_at'=>now(),'updated_at'=>now()]);
                $this->inventory->postMovement((string)$item->id,$date,-$qty,$type==='UNIFORM'?'UNIFORM_OUT':'ATTRIBUTE_OUT','UNIFORM_I11',$id,$lineId,$type==='ATTRIBUTE'?$manual:$purchase,$squad+$sizeCharge,$company,$actorUserId,['document_no'=>$doc,'recipient_name'=>$recipient,'outbound_type'=>$type]);
                if($type==='UNIFORM') {
                    $amount=round(($squad+$sizeCharge)*$qty,2);
                    if($amount>0) DB::table(self::DEDUCTIONS)->insert(['id'=>(string)Str::ulid(),'outbound_id'=>$id,'outbound_line_id'=>$lineId,'employee_id'=>(string)$employee->id,'payroll_month'=>(string)$payload['payroll_month'],'amount'=>$amount,'status'=>'PENDING','created_at'=>now(),'updated_at'=>now()]);
                }
            }
            if ($type === 'UNIFORM') {
                $cutoffId = $this->payrollDeductions->attachPendingToExistingDraft((string)$employee->id, (string)$payload['payroll_month']);
                if ($cutoffId) {
                    $cutoff = \App\Models\HrPayrollCutoff::query()->find($cutoffId);
                    if ($cutoff) {
                        foreach ($cutoff->slips()->where('employee_id', (string)$employee->id)->get() as $slip) $this->payroll->recalculateAndSaveSlip($slip);
                        $this->payroll->refreshSummary($cutoff);
                    }
                }
            }
            return $this->show($id);
        });
    }

    public function cancel(string $id, ?string $actorUserId, string $reason): array
    {
        $this->assertReady();
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Alasan pembatalan wajib diisi.');
        foreach (['cancelled_at', 'cancelled_by_user_id', 'cancel_reason'] as $column) {
            if (! Schema::hasColumn(self::OUTBOUNDS, $column)) {
                throw new RuntimeException('Schema pembatalan Uniform Keluar belum tersedia. Jalankan php artisan migrate.');
            }
        }

        return DB::transaction(function () use ($id, $actorUserId, $reason): array {
            $header = DB::table(self::OUTBOUNDS)->where('id', $id)->lockForUpdate()->first();
            if (! $header) throw new DomainException('Uniform Keluar tidak ditemukan.');
            if (strtoupper((string) $header->outbound_type) !== 'UNIFORM') throw new DomainException('Pembatalan ini hanya berlaku untuk Uniform Keluar.');
            if (strtoupper((string) $header->status) === 'CANCELLED') return $this->show($id);
            if (strtoupper((string) $header->status) !== 'POSTED') throw new DomainException('Hanya Uniform Keluar berstatus POSTED yang dapat dibatalkan.');

            $lines = DB::table(self::LINES)->where('outbound_id', $id)->orderBy('created_at')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new DomainException('Detail Uniform Keluar tidak ditemukan.');
            $deductions = DB::table(self::DEDUCTIONS)->where('outbound_id', $id)->orderBy('created_at')->lockForUpdate()->get();

            $slips = [];
            $cutoffs = [];
            foreach ($deductions as $deduction) {
                $status = strtoupper((string) $deduction->status);
                if ($status === 'SETTLED') {
                    throw new DomainException('Uniform Keluar tidak dapat dibatalkan karena potongan gaji sudah final/LUNAS. Reopen payroll terlebih dahulu.');
                }
                if ($status !== 'CLAIMED') continue;
                if (! $deduction->payroll_cutoff_id || ! $deduction->payroll_slip_id) {
                    throw new DomainException('Potongan Uniform sudah ter-claim tetapi referensi payroll tidak lengkap.');
                }

                $cutoffId = (string) $deduction->payroll_cutoff_id;
                if (! isset($cutoffs[$cutoffId])) {
                    $cutoff = \App\Models\HrPayrollCutoff::query()->whereKey($cutoffId)->lockForUpdate()->first();
                    if (! $cutoff || strtolower((string) $cutoff->status) !== 'draft') {
                        throw new DomainException('Uniform Keluar tidak dapat dibatalkan karena potongan sudah masuk payroll yang bukan DRAFT. Reopen payroll terlebih dahulu.');
                    }
                    $cutoffs[$cutoffId] = $cutoff;
                }

                $slipId = (string) $deduction->payroll_slip_id;
                if (! isset($slips[$slipId])) {
                    $slip = \App\Models\HrPayrollSlip::query()->whereKey($slipId)->lockForUpdate()->first();
                    if (! $slip || (string) $slip->cutoff_id !== $cutoffId) {
                        throw new DomainException('Slip payroll potongan Uniform tidak valid.');
                    }
                    $slips[$slipId] = $slip;
                }
            }

            foreach ($deductions as $deduction) {
                $status = strtoupper((string) $deduction->status);
                if ($status === 'CLAIMED') {
                    $slip = $slips[(string) $deduction->payroll_slip_id];
                    $slip->other_deduction = max(0, round((float) $slip->other_deduction - (float) $deduction->amount, 2));
                }
                if (in_array($status, ['PENDING', 'CLAIMED'], true)) {
                    DB::table(self::DEDUCTIONS)->where('id', $deduction->id)->update([
                        'status' => 'CANCELLED',
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach ($lines as $line) {
                $this->inventory->reverseOutboundMovement(
                    (string) $line->uniform_item_id,
                    now()->format('Y-m-d'),
                    (int) $line->quantity,
                    'UNIFORM_I11',
                    $id,
                    (string) $line->id,
                    $actorUserId,
                    [
                        'document_no' => (string) $header->document_no,
                        'cancel_reason' => $reason,
                        'cancelled_at' => now()->toIso8601String(),
                    ],
                );
            }

            foreach ($slips as $slip) {
                $this->syncUniformDeductionNote($slip);
                $this->payroll->recalculateAndSaveSlip($slip);
            }
            foreach ($cutoffs as $cutoff) $this->payroll->refreshSummary($cutoff);

            DB::table(self::OUTBOUNDS)->where('id', $id)->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actorUserId,
                'cancel_reason' => mb_substr($reason, 0, 1000),
                'updated_at' => now(),
            ]);

            return $this->show($id);
        });
    }

    public function recapPreview(array $filters): array
    {
        $this->assertReady();
        $base=DB::table(self::LINES.' as l')->join(self::OUTBOUNDS.' as h','h.id','=','l.outbound_id')->join('HR_uniform_items as i','i.id','=','l.uniform_item_id')
            ->leftJoin('employees as e','e.id','=','h.employee_id')->leftJoin(self::DEDUCTIONS.' as d','d.outbound_line_id','=','l.id')
            ->where('h.status','POSTED')
            ->when(!empty($filters['date_from']),fn($q)=>$q->whereDate('h.outbound_date','>=',$filters['date_from']))
            ->when(!empty($filters['date_to']),fn($q)=>$q->whereDate('h.outbound_date','<=',$filters['date_to']))
            ->when(!empty($filters['company_code']),fn($q)=>$q->where('i.company_code',strtoupper((string)$filters['company_code'])))
            ->orderBy('h.outbound_date')->orderBy('h.created_at')->orderByRaw('COALESCE(i.source_row,9999)')
            ->get(['h.id as outbound_id','h.document_no','h.outbound_type','h.outbound_date','h.employee_id','h.recipient_name','h.outlet_name_snapshot','h.payroll_month','h.notes','e.nisj','l.id as line_id','l.quantity','l.purchase_price','l.squad_charge','l.size_charge','l.company_charge','l.manual_price','i.code','i.name','i.company_code','i.item_kind','i.size','d.status as deduction_status','d.payroll_cutoff_id','d.payroll_slip_id']);
        $uniform=[];$attribute=[];
        foreach($base as $r){
            $row=['outbound_id'=>(string)$r->outbound_id,'document_no'=>(string)$r->document_no,'outbound_date'=>(string)$r->outbound_date,'recipient_name'=>(string)$r->recipient_name,'nisj'=>$r->nisj,'outlet_name'=>$r->outlet_name_snapshot ?: '-','code'=>(string)$r->code,'name'=>(string)$r->name,'size'=>$r->size,'company_code'=>(string)$r->company_code,'quantity'=>(int)$r->quantity,'purchase_price'=>(float)$r->purchase_price,'squad_charge'=>(float)$r->squad_charge,'size_charge'=>(float)$r->size_charge,'company_charge'=>(float)$r->company_charge,'manual_price'=>(float)$r->manual_price,'payroll_month'=>$r->payroll_month,'deduction_status'=>$this->deductionLabel($r->deduction_status),'notes'=>$r->notes];
            strtoupper((string)$r->outbound_type)==='UNIFORM'?$uniform[]=$row:$attribute[]=$row;
        }
        return ['uniform_rows'=>$uniform,'attribute_rows'=>$attribute,'stats'=>['uniform_rows'=>count($uniform),'attribute_rows'=>count($attribute),'pending_deductions'=>collect($uniform)->where('deduction_status','BELUM TERPOTONG')->count(),'settled_deductions'=>collect($uniform)->where('deduction_status','LUNAS')->count()]];
    }


    private function syncUniformDeductionNote(\App\Models\HrPayrollSlip $slip): void
    {
        $prefix = 'Potongan Uniform/Atribut:';
        $activeTokens = DB::table(self::DEDUCTIONS)
            ->where('payroll_slip_id', (string) $slip->id)
            ->whereIn('status', ['CLAIMED', 'SETTLED'])
            ->pluck('amount')
            ->map(fn ($amount) => $prefix.' Rp '.number_format((float) $amount, 0, ',', '.'))
            ->unique()
            ->values()
            ->all();

        $parts = preg_split('/\s*\|\s*/u', trim((string) ($slip->manual_note ?? ''))) ?: [];
        $parts = array_values(array_filter($parts, function (string $part) use ($prefix, $activeTokens): bool {
            $part = trim($part);
            if ($part === '') return false;
            if (! str_starts_with($part, $prefix)) return true;
            return in_array($part, $activeTokens, true);
        }));
        foreach ($activeTokens as $token) {
            if (! in_array($token, $parts, true)) $parts[] = $token;
        }
        $slip->manual_note = mb_substr(implode(' | ', $parts), 0, 500);
    }

    private function applyActiveSquadEmployeeFilter($query): void
    {
        if (! Schema::hasTable('HR_squads')) return;
        $hasDeleted = Schema::hasColumn('HR_squads', 'deleted_at');
        $hasStatus = Schema::hasColumn('HR_squads', 'status');
        $hasUser = Schema::hasColumn('HR_squads', 'user_id') && Schema::hasColumn('employees', 'user_id');
        $hasNisj = Schema::hasColumn('HR_squads', 'nisj') && Schema::hasColumn('employees', 'nisj');
        if (! $hasUser && ! $hasNisj) return;

        $query->whereExists(function ($sq) use ($hasDeleted, $hasStatus, $hasUser, $hasNisj): void {
            $sq->selectRaw('1')->from('HR_squads as active_sq');
            if ($hasDeleted) $sq->whereNull('active_sq.deleted_at');
            if ($hasStatus) {
                $sq->where(function ($status): void {
                    $status->whereNull('active_sq.status')->orWhereRaw("LOWER(TRIM(active_sq.status)) = 'active'");
                });
            }

            if ($hasUser && $hasNisj) {
                $sq->where(function ($match): void {
                    $match->where(function ($byUser): void {
                        $byUser->whereNotNull('active_sq.user_id')->whereNotNull('e.user_id')->whereColumn('active_sq.user_id', 'e.user_id');
                    })->orWhere(function ($byNisj): void {
                        $byNisj->where(function ($missingUser): void {
                            $missingUser->whereNull('active_sq.user_id')->orWhereNull('e.user_id');
                        })->whereRaw('LOWER(TRIM(active_sq.nisj)) = LOWER(TRIM(e.nisj))');
                    });
                });
            } elseif ($hasUser) {
                $sq->whereNotNull('active_sq.user_id')->whereNotNull('e.user_id')->whereColumn('active_sq.user_id', 'e.user_id');
            } else {
                $sq->whereRaw('LOWER(TRIM(active_sq.nisj)) = LOWER(TRIM(e.nisj))');
            }
        });
    }

    private function employeeHasActiveSquad(object $employee): bool
    {
        if (! Schema::hasTable('HR_squads')) return true;
        $base = DB::table('HR_squads');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $base->whereNull('deleted_at');
        if (Schema::hasColumn('HR_squads', 'status')) {
            $base->where(function ($status): void {
                $status->whereNull('status')->orWhereRaw("LOWER(TRIM(status)) = 'active'");
            });
        }

        $hasUserColumn = Schema::hasColumn('HR_squads', 'user_id');
        $userId = trim((string) ($employee->user_id ?? ''));
        if ($hasUserColumn && $userId !== '' && (clone $base)->where('user_id', $userId)->exists()) return true;

        $nisj = mb_strtolower(trim((string) ($employee->nisj ?? '')));
        if (! Schema::hasColumn('HR_squads', 'nisj') || $nisj === '') return false;

        $byNisj = (clone $base)->whereRaw('LOWER(TRIM(nisj)) = ?', [$nisj]);
        // If the employee already has a concrete user link, NISJ is only a legacy
        // fallback for an HR_squads row that has not yet been linked to users.
        if ($hasUserColumn && $userId !== '') $byNisj->whereNull('user_id');
        return $byNisj->exists();
    }

    private function deductionLabel(?string $status): string
    { $status=strtoupper((string)$status); return $status==='SETTLED'?'LUNAS':($status==='CANCELLED'?'DIBATALKAN':'BELUM TERPOTONG'); }
    private function outletName(?string $id): ?string
    { return $id?DB::table('outlets')->where('id',$id)->value('name'):null; }
    private function lineStats(array $ids): array
    { if($ids===[])return[];return DB::table(self::LINES)->whereIn('outbound_id',$ids)->groupBy('outbound_id')->get(['outbound_id',DB::raw('COUNT(*) line_count'),DB::raw('SUM(quantity) total_qty'),DB::raw('SUM(quantity*(squad_charge+size_charge)) squad_total'),DB::raw('SUM(quantity*company_charge) company_total'),DB::raw('SUM(quantity*manual_price) manual_total')])->mapWithKeys(fn($r)=>[(string)$r->outbound_id=>['line_count'=>(int)$r->line_count,'total_qty'=>(int)$r->total_qty,'squad_total'=>(float)$r->squad_total,'company_total'=>(float)$r->company_total,'manual_total'=>(float)$r->manual_total]])->all(); }
    private function formatPaginator(LengthAwarePaginator $p, callable $f): array
    { return ['items'=>collect($p->items())->map($f)->values()->all(),'meta'=>['page'=>$p->currentPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'last_page'=>max(1,$p->lastPage())]]; }
    private function assertReady(): void
    { foreach([self::OUTBOUNDS,self::LINES,self::DEDUCTIONS,'HR_uniform_items','HR_uniform_stock_balances','HR_uniform_movements'] as $t) if(!Schema::hasTable($t)) throw new RuntimeException('Schema Uniform I10/I11 belum tersedia. Jalankan php artisan migrate.'); }
}
