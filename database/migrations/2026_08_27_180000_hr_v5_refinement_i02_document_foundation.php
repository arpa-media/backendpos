<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->createNumberSequence();
        $this->extendContractDocuments();
        $this->extendWarningLetters();
        $this->seedCanonicalTemplates();
    }

    public function down(): void
    {
        // Remove only data introduced by this iteration. Legacy template rows remain untouched.
        if (Schema::hasTable('HR_contract_document_templates')) {
            DB::table('HR_contract_document_templates')
                ->whereIn('document_type', array_keys($this->templates()))
                ->delete();
        }

        if (Schema::hasTable('HR_warning_letters')) {
            Schema::table('HR_warning_letters', function (Blueprint $table): void {
                if (Schema::hasColumn('HR_warning_letters', 'branding_snapshot')) $table->dropColumn('branding_snapshot');
                if (Schema::hasColumn('HR_warning_letters', 'letter_code')) $table->dropColumn('letter_code');
                if (Schema::hasColumn('HR_warning_letters', 'company_code')) $table->dropColumn('company_code');
                if (Schema::hasColumn('HR_warning_letters', 'template_key')) $table->dropColumn('template_key');
            });
        }

        if (Schema::hasTable('HR_contract_documents')) {
            Schema::table('HR_contract_documents', function (Blueprint $table): void {
                if (Schema::hasColumn('HR_contract_documents', 'branding_snapshot')) $table->dropColumn('branding_snapshot');
                if (Schema::hasColumn('HR_contract_documents', 'letter_code')) $table->dropColumn('letter_code');
                if (Schema::hasColumn('HR_contract_documents', 'company_code')) $table->dropColumn('company_code');
                if (Schema::hasColumn('HR_contract_documents', 'template_key')) $table->dropColumn('template_key');
            });
        }

        Schema::dropIfExists('HR_document_number_sequences');
    }

    private function createNumberSequence(): void
    {
        if (Schema::hasTable('HR_document_number_sequences')) return;

        Schema::create('HR_document_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('letter_code', 16);
            $table->string('company_code', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['letter_code', 'company_code', 'year'], 'hr_doc_num_code_company_year_uq');
            $table->index(['company_code', 'year'], 'hr_doc_num_company_year_idx');
        });
    }

    private function extendContractDocuments(): void
    {
        if (! Schema::hasTable('HR_contract_documents')) return;

        if (! Schema::hasColumn('HR_contract_documents', 'template_key')) {
            Schema::table('HR_contract_documents', function (Blueprint $table): void {
                $table->string('template_key', 80)->nullable()->after('template_id');
            });
        }
        if (! Schema::hasColumn('HR_contract_documents', 'company_code')) {
            Schema::table('HR_contract_documents', function (Blueprint $table): void {
                $table->string('company_code', 16)->nullable()->after('template_key');
            });
        }
        if (! Schema::hasColumn('HR_contract_documents', 'letter_code')) {
            Schema::table('HR_contract_documents', function (Blueprint $table): void {
                $table->string('letter_code', 16)->nullable()->after('company_code');
            });
        }
        if (! Schema::hasColumn('HR_contract_documents', 'branding_snapshot')) {
            Schema::table('HR_contract_documents', function (Blueprint $table): void {
                $table->json('branding_snapshot')->nullable()->after('payload_snapshot');
            });
        }
    }

    private function extendWarningLetters(): void
    {
        if (! Schema::hasTable('HR_warning_letters')) return;

        if (! Schema::hasColumn('HR_warning_letters', 'template_key')) {
            Schema::table('HR_warning_letters', function (Blueprint $table): void {
                $table->string('template_key', 80)->nullable()->after('recommendation_id');
            });
        }
        if (! Schema::hasColumn('HR_warning_letters', 'company_code')) {
            Schema::table('HR_warning_letters', function (Blueprint $table): void {
                $table->string('company_code', 16)->nullable()->after('template_key');
            });
        }
        if (! Schema::hasColumn('HR_warning_letters', 'letter_code')) {
            Schema::table('HR_warning_letters', function (Blueprint $table): void {
                $table->string('letter_code', 16)->nullable()->after('company_code');
            });
        }
        if (! Schema::hasColumn('HR_warning_letters', 'branding_snapshot')) {
            Schema::table('HR_warning_letters', function (Blueprint $table): void {
                $table->json('branding_snapshot')->nullable()->after('employee_snapshot');
            });
        }
    }

    private function seedCanonicalTemplates(): void
    {
        if (! Schema::hasTable('HR_contract_document_templates')) return;

        $now = now();
        foreach ($this->templates() as $key => $template) {
            $existing = DB::table('HR_contract_document_templates')
                ->where('document_type', $key)
                ->where('version', 1)
                ->first();

            DB::table('HR_contract_document_templates')->updateOrInsert(
                ['document_type' => $key, 'version' => 1],
                [
                    'id' => (string) ($existing->id ?? Str::ulid()),
                    'name' => $template['name'],
                    'title_template' => $template['title_template'],
                    'body_template' => $template['body_template'],
                    'is_active' => true,
                    'created_by_user_id' => $existing->created_by_user_id ?? null,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function templates(): array
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
            'promotion_bkjb' => ['name' => 'TEMPLATE SK PROMOSI - BKJB', 'title_template' => 'Surat Promosi', 'body_template' => $promotion],
            'promotion_mdmf' => ['name' => 'TEMPLATE SK PROMOSI - MDMF', 'title_template' => 'Surat Promosi', 'body_template' => $promotion],
            'transfer_mdmf' => ['name' => 'TEMPLATE SK MUTASI - MDMF', 'title_template' => 'Surat Perintah Mutasi Kerja', 'body_template' => $transfer],
            'transfer_bkjb' => ['name' => 'TEMPLATE SK MUTASI - BKJB', 'title_template' => 'Surat Perintah Mutasi Kerja', 'body_template' => $transfer],
            'demotion_mdmf' => ['name' => 'TEMPLATE SK DEMOSI JABATAN - MDMF', 'title_template' => 'Surat Keterangan Demosi', 'body_template' => $demotion],
            'demotion_bkjb' => ['name' => 'TEMPLATE SK DEMOSI JABATAN - BKJB', 'title_template' => 'Surat Keterangan Demosi', 'body_template' => $demotion],
            'warning_bkjb' => ['name' => 'TEMPLATE SURAT PERINGATAN - BKJB', 'title_template' => 'Surat Peringatan {{sp_level}}', 'body_template' => $warning],
            'warning_mdmf' => ['name' => 'TEMPLATE SURAT PERINGATAN - MDMF', 'title_template' => 'Surat Peringatan {{sp_level}}', 'body_template' => $warning],
            'verbal_warning' => ['name' => 'TEMPLATE TEGURAN LISAN', 'title_template' => 'FORM TEGURAN LISAN', 'body_template' => $verbal],
        ];
    }
};
