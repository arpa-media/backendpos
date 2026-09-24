<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pur_document_attachments')) {
            Schema::create('pur_document_attachments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('document_type', 40);
                $table->string('document_id', 64);
                $table->string('attachment_type', 64)->default('SUPPORTING_DOCUMENT');
                $table->string('original_name');
                $table->string('stored_name');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size')->default(0);
                $table->unsignedBigInteger('original_file_size')->nullable();
                $table->string('compression_status', 64)->nullable();
                $table->char('sha256', 64)->nullable();
                $table->string('disk', 40)->default('local');
                $table->text('path');
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->ulid('uploaded_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['document_type', 'document_id'], 'pur04_att_doc_idx');
                $table->index(['uploaded_by_user_id', 'created_at'], 'pur04_att_user_idx');
            });
        } else {
            Schema::table('pur_document_attachments', function (Blueprint $table): void {
                if (! Schema::hasColumn('pur_document_attachments', 'original_file_size')) {
                    $table->unsignedBigInteger('original_file_size')->nullable()->after('file_size');
                }
                if (! Schema::hasColumn('pur_document_attachments', 'compression_status')) {
                    $table->string('compression_status', 64)->nullable()->after('original_file_size');
                }
                if (! Schema::hasColumn('pur_document_attachments', 'sha256')) {
                    $table->char('sha256', 64)->nullable()->after('compression_status');
                }
            });
        }

        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where('code', 'purchasing-fund-requests')
                ->update([
                    'name' => 'Fund Requests',
                    'path' => '/purchasing/fund-requests',
                    'permission_view' => 'purchasing.fund_request.view',
                    'permission_create' => 'purchasing.fund_request.create',
                    'permission_update' => 'purchasing.fund_request.update',
                    'permission_delete' => 'purchasing.fund_request.delete',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where('code', 'purchasing-fund-requests')
                ->update(['name' => 'Pengajuan Dana', 'updated_at' => now()]);
        }
        // Attachment table/data intentionally retained to avoid destructive rollback.
    }
};
