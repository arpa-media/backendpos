<?php

namespace Tests\Feature\Reporting;

use App\Support\TransactionDate;
use Tests\TestCase;

class TransactionDateInvariantTest extends TestCase
{
    public function test_jakarta_business_day_starts_at_midnight(): void
    {
        $window = TransactionDate::businessDateWindow('2026-09-21', '2026-09-21', 'Asia/Jakarta');

        $this->assertSame(0, $window['start_hour']);
        $this->assertSame('2026-09-21 00:00:00', $window['from_local']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-22 00:00:00', $window['to_exclusive_local']->format('Y-m-d H:i:s'));
    }

    public function test_makassar_business_day_keeps_one_am_cutoff(): void
    {
        $window = TransactionDate::businessDateWindow('2026-09-21', '2026-09-21', 'Asia/Makassar');

        $this->assertSame(1, $window['start_hour']);
        $this->assertSame('2026-09-21 01:00:00', $window['from_local']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-22 01:00:00', $window['to_exclusive_local']->format('Y-m-d H:i:s'));
    }

    public function test_wita_alias_resolves_to_makassar_cutoff(): void
    {
        $this->assertSame('Asia/Makassar', TransactionDate::normalizeTimezone('WITA'));
        $this->assertSame(1, TransactionDate::businessDayStartHour('WITA'));
    }
}
