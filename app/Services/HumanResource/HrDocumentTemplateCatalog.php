<?php

namespace App\Services\HumanResource;

use Illuminate\Validation\ValidationException;

class HrDocumentTemplateCatalog
{
    public const PROMOTION_BKJB = 'promotion_bkjb';
    public const PROMOTION_MDMF = 'promotion_mdmf';
    public const TRANSFER_MDMF = 'transfer_mdmf';
    public const TRANSFER_BKJB = 'transfer_bkjb';
    public const DEMOTION_MDMF = 'demotion_mdmf';
    public const DEMOTION_BKJB = 'demotion_bkjb';
    public const WARNING_BKJB = 'warning_bkjb';
    public const WARNING_MDMF = 'warning_mdmf';
    public const VERBAL_WARNING = 'verbal_warning';

    public function definitions(): array
    {
        $promotion = <<<'TXT'
Surat Promosi Kerja ini ditujukan kepada Saudara {{full_name}} dengan Nomor Induk Squad Jaya {{nisj}}.

Surat promosi ini dibuat untuk menjadi dasar keputusan resmi yang dikeluarkan oleh {{company_name}} sebagai badan hukum atas merek dagang Toko Kopi Jaya, dalam memenuhi kebutuhan operasional pada outlet {{new_outlet_name}}.

{{company_name}} melakukan promosi kepada:
{{full_name}}

sebagai {{new_position}}, Cabang {{new_outlet_name}} yang terletak pada {{new_outlet_address}}.

Masa Jabatan baru berlaku sejak tanggal {{effective_date}}. Adapun hal-hal mengenai Job Description serta ketentuan lainnya, akan diatur pada surat keterangan lain, diluar surat penunjukan ini.

Demikian surat promosi ini dibuat, agar dapat dipergunakan sebagaimana mestinya.
[[PAGE_BREAK]]
LAMPIRAN 1

Skema penghitungan gaji pada posisi atau jabatan tersebut diatas adalah sebagai berikut:
1. Gaji bulanan dengan minimal hari kerja adalah sebanyak 25 hari kerja.
2. Jika hari kerja pada cut off bulan tersebut dibawah 25 hari, maka gaji akan dihitung proporsional sesuai jumlah hari kerja.
3. Jika jumlah hari kerja melebihi 25 hari, maka gaji yang akan diterima adalah sesuai dengan gaji awal. Kecuali dalam hal ini ada lembur, maka akan terhitung dan terbayarkan pada gaji bulan tersebut.
TXT;

        $transfer = <<<'TXT'
Berdasarkan hasil evaluasi dan pertimbangan Management, Surat Perintah Mutasi Kerja ini ditujukan kepada Saudara/i {{full_name}} Squad Toko Kopi Jaya {{division}} sebagai Squad {{position}} outlet {{current_outlet_name}} untuk melakukan mutasi atau perpindahan lokasi kerja yang sebelumnya bertempat di Outlet {{current_outlet_name}} ke Outlet {{new_outlet_name}}. Perpindahan lokasi tersebut efektif sejak tanggal {{effective_date}}.

Dimohon Saudara/i dapat menyelesaikan urusan dengan baik di outlet lama dan menyiapkan dan menyesuaikan segala sesuatunya dengan baik di outlet yang baru.

Demikian surat mutasi ini kami sampaikan. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.
TXT;

        $demotion = <<<'TXT'
Berdasarkan hasil evaluasi dan pertimbangan manajemen, maka dengan ini kami sampaikan kepada:
Nama : {{full_name}}
NISJ : {{nisj}}
Jabatan : {{position}}

Bahwa adanya demosi atau penurunan jabatan dari posisi {{position}} outlet {{current_outlet_name}} ke posisi {{new_position}} outlet {{new_outlet_name}} berdasarkan evaluasi kinerja. Perubahan jabatan tersebut efektif berlaku pada tanggal {{effective_date}}, demikian juga terkait dengan gaji kembali ke nominal {{new_salary_formatted}}.

Dimohon Saudara/i dapat menyelesaikan urusan dengan baik pada posisi lama serta menyiapkan dan menyesuaikan segala sesuatunya dengan baik pada posisi yang baru.

Demikian surat keterangan demosi ini kami sampaikan. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.
TXT;

        $warning = <<<'TXT'
Surat peringatan ini dibuat oleh perusahaan dan ditujukan kepada:
Nama : {{full_name}}
NISJ : {{nisj}}
Jabatan : {{position}}
Penempatan : {{current_outlet_name}}

Surat ini diterbitkan berdasarkan tindakan pelanggaran kedisiplinan berulang atas tata tertib yang telah Saudara lakukan. Saudara diketahui telah melakukan pelanggaran berupa:
1. {{reason}}

Berdasarkan peraturan yang telah berlaku. Maka perusahaan mengeluarkan surat peringatan sebagai langkah lanjutan dan teguran keras kepada saudara agar lebih professional lagi dalam bekerja serta mematuhi tata tertib perusahaan.

Surat Peringatan ini berlaku selama ___ (____) bulan terhitung mulai tanggal _______________ s/d _______________. Apabila dalam jangka waktu tersebut saudara kembali mengulangi hal yang sama atau melakukan pelanggaran yang lain maka akan diberikan sanksi sesuai dengan kebijakan perusahaan/keputusan pimpinan.

Demikian surat peringatan ini dibuat untuk dijadikan acuan melakukan introspeksi dan evaluasi. Atas perhatian Saudara, kami ucapkan terima kasih.
TXT;

        $verbal = <<<'TXT'
FORM TEGURAN LISAN
HARI / TANGGAL : {{issue_date}}
Nama : {{full_name}}
Outlet : {{current_outlet_name}}
Jabatan : {{position}}

DENGAN INI TELAH MENERIMA TEGURAN LISAN
REASON : {{reason}}

SQUAD                SPV                HRD
TXT;

        return [
            self::PROMOTION_BKJB => $this->definition('TEMPLATE SK PROMOSI - BKJB', 'promotion', 'BKJB', 'SPN', 'letter', 'TEMPLATE SK PROMOSI - BKJB.docx', 'Surat Promosi', $promotion, 10),
            self::PROMOTION_MDMF => $this->definition('TEMPLATE SK PROMOSI - MDMF', 'promotion', 'MDMF', 'SPN', 'letter', 'TEMPLATE SK PROMOSI - MDMF.docx', 'Surat Promosi', $promotion, 20),
            self::TRANSFER_MDMF => $this->definition('TEMPLATE SK MUTASI - MDMF', 'transfer', 'MDMF', 'SPMK', 'letter', 'TEMPLATE SK MUTASI - MDMF.docx', 'Surat Perintah Mutasi Kerja', $transfer, 30),
            self::TRANSFER_BKJB => $this->definition('TEMPLATE SK MUTASI - BKJB', 'transfer', 'BKJB', 'SPMK', 'letter', 'TEMPLATE SK MUTASI - BKJB.docx', 'Surat Perintah Mutasi Kerja', $transfer, 40),
            self::DEMOTION_MDMF => $this->definition('TEMPLATE SK DEMOSI JABATAN - MDMF', 'demotion', 'MDMF', 'SKD', 'letter', 'TEMPLATE SK DEMOSI JABATAN - MDMF.docx', 'Surat Keterangan Demosi', $demotion, 50),
            self::DEMOTION_BKJB => $this->definition('TEMPLATE SK DEMOSI JABATAN - BKJB', 'demotion', 'BKJB', 'SKD', 'letter', 'TEMPLATE SK DEMOSI JABATAN - BKJB.docx', 'Surat Keterangan Demosi', $demotion, 60),
            self::WARNING_BKJB => $this->definition('TEMPLATE SURAT PERINGATAN - BKJB', 'warning', 'BKJB', 'SP', 'letter', 'TEMPLATE SURAT PERINGATAN - BKJB.docx', 'Surat Peringatan {{sp_level}}', $warning, 70),
            self::WARNING_MDMF => $this->definition('TEMPLATE SURAT PERINGATAN - MDMF', 'warning', 'MDMF', 'SP', 'letter', 'TEMPLATE SURAT PERINGATAN - MDMF.docx', 'Surat Peringatan {{sp_level}}', $warning, 80),
            self::VERBAL_WARNING => $this->definition('TEMPLATE TEGURAN LISAN', 'verbal_warning', null, null, 'verbal_warning_single', 'TEMPLATE TEGURAN LISAN.docx', 'FORM TEGURAN LISAN', $verbal, 90),
        ];
    }

    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    public function definitionFor(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if (! $definition) {
            throw ValidationException::withMessages(['template_key' => ['Template HR tidak terdaftar pada canonical catalog.']]);
        }
        return $definition;
    }

    public function keyFor(string $businessType, ?string $companyCode): string
    {
        $businessType = strtolower(trim($businessType));
        $companyCode = $companyCode ? strtoupper(trim($companyCode)) : null;

        foreach ($this->definitions() as $key => $definition) {
            if ($definition['business_type'] !== $businessType) continue;
            if ($definition['company_code'] === null || $definition['company_code'] === $companyCode) return $key;
        }

        throw ValidationException::withMessages(['template_key' => ['Template HR untuk jenis dokumen/PT tersebut belum tersedia.']]);
    }

    public function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static fn ($m) => (string) ($values[$m[1]] ?? '-'), $template) ?? $template;
    }

    private function definition(
        string $name,
        string $businessType,
        ?string $companyCode,
        ?string $letterCode,
        string $layoutKey,
        string $sourceName,
        string $titleTemplate,
        string $bodyTemplate,
        int $displayOrder,
    ): array {
        return [
            'name' => $name,
            'business_type' => $businessType,
            'company_code' => $companyCode,
            'letter_code' => $letterCode,
            'layout_key' => $layoutKey,
            'source_name' => $sourceName,
            'title_template' => $titleTemplate,
            'body_template' => $bodyTemplate,
            'display_order' => $displayOrder,
        ];
    }
}
