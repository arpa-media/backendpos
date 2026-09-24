<?php

namespace App\Services\HumanResource;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class HrDevelopmentCertificateTemplateService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const MERGE_FIELDS = ['{{name}}','{{nisj}}','{{development}}','{{batch}}','{{result}}','{{score}}','{{completed_date}}','{{certificate_no}}'];

    public function store(string $developmentId, UploadedFile $file, ?string $userId, ?string $name=null): array
    {
        if (!$file->isValid() || $file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['template'=>['Template wajib valid dan maksimal 10 MB.']]);
        }
        if (!function_exists('gzencode') || !function_exists('gzdecode')) throw ValidationException::withMessages(['template'=>['PHP zlib wajib aktif.']]);
        $original=$this->safeName($file->getClientOriginalName());
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','png','jpg','jpeg','webp'],true)) throw ValidationException::withMessages(['template'=>['Template hanya mendukung PDF/PNG/JPG/WEBP.']]);
        $payload=file_get_contents((string)$file->getRealPath());
        if ($payload===false) throw new RuntimeException('Template tidak dapat dibaca.');
        $mime=$this->assertSignature($payload,$ext);
        $compressed=gzencode($payload,9,ZLIB_ENCODING_GZIP); if ($compressed===false) throw new RuntimeException('Kompresi template gagal.');
        $version=(int)(DB::table('HR_development_certificate_templates')->where('development_id',$developmentId)->max('version') ?? 0)+1;
        $id=(string)Str::ulid(); $path="hr/development/{$developmentId}/certificate-template-v{$version}-{$id}.gz";
        Storage::disk('local')->put($path,$compressed);
        DB::transaction(function() use($developmentId,$id,$version,$name,$original,$mime,$path,$payload,$compressed,$userId){
            DB::table('HR_development_certificate_templates')->where('development_id',$developmentId)->update(['is_active'=>false,'updated_at'=>now()]);
            DB::table('HR_development_certificate_templates')->insert([
                'id'=>$id,'development_id'=>$developmentId,'version'=>$version,'name'=>trim((string)$name)?:"Template Certificate v{$version}",
                'original_name'=>$original,'mime_type'=>$mime,'storage_disk'=>'local','storage_path'=>$path,'compression_method'=>'gzip',
                'source_size_bytes'=>strlen($payload),'compressed_size_bytes'=>strlen($compressed),'sha256'=>hash('sha256',$payload),
                'merge_fields'=>json_encode(self::MERGE_FIELDS,JSON_UNESCAPED_UNICODE),'is_active'=>true,'uploaded_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        return $this->present(DB::table('HR_development_certificate_templates')->where('id',$id)->first());
    }

    public function read(object $row): string
    {
        $binary=Storage::disk((string)($row->storage_disk?:'local'))->get((string)$row->storage_path);
        $payload=($row->compression_method??'')==='gzip'?gzdecode($binary):$binary;
        if ($payload===false || !hash_equals((string)$row->sha256,hash('sha256',$payload))) throw new RuntimeException('Template certificate corrupt atau checksum mismatch.');
        return $payload;
    }

    public function active(string $developmentId): ?array
    { return $this->present(DB::table('HR_development_certificate_templates')->where('development_id',$developmentId)->where('is_active',true)->orderByDesc('version')->first()); }

    public function present(?object $r): ?array
    {
        if(!$r)return null; return ['id'=>(string)$r->id,'version'=>(int)$r->version,'name'=>(string)$r->name,'original_name'=>(string)$r->original_name,'mime_type'=>(string)$r->mime_type,'source_size_bytes'=>(int)$r->source_size_bytes,'merge_fields'=>$this->decode($r->merge_fields),'is_active'=>(bool)$r->is_active];
    }

    private function assertSignature(string $p,string $ext): string
    {
        if($ext==='pdf'){if(strpos(substr($p,0,1024),'%PDF-')===false)throw ValidationException::withMessages(['template'=>['Isi file bukan PDF valid.']]);return'application/pdf';}
        if(!function_exists('getimagesizefromstring') || @getimagesizefromstring($p)===false)throw ValidationException::withMessages(['template'=>['Isi file bukan image valid.']]);
        return match($ext){'png'=>'image/png','webp'=>'image/webp',default=>'image/jpeg'};
    }
    private function safeName(string $n): string { $n=trim(str_replace(["\0","\r","\n",'/','\\'],['','','','_','_'],$n)); return mb_substr($n?:'template',0,240); }
    private function decode(mixed $v): array { if(is_array($v))return$v; $x=json_decode((string)$v,true);return is_array($x)?$x:[]; }
}
