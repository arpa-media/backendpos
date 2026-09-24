<?php

namespace App\Exceptions;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use RuntimeException;

class ReportMaterializationNotReadyException extends RuntimeException
{
    public function __construct(
        public readonly array $reportingSource,
        string $message = 'Data report untuk rentang tanggal ini belum selesai dimaterialisasi. Jalankan atau tunggu proses melalui Console > Control Center.'
    ) {
        parent::__construct($message);
    }

    public function render($request)
    {
        return ApiResponse::error(
            $this->getMessage(),
            'REPORT_DAILY_SUMMARY_NOT_READY',
            409,
            [],
            ['reporting_source' => $this->reportingSource]
        );
    }
}
