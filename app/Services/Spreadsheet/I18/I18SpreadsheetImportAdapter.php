<?php

namespace App\Services\Spreadsheet\I18;

interface I18SpreadsheetImportAdapter
{
    public function moduleKey(): string;
    /** @return array<string,array<int,string>|string> */
    public function aliases(): array;
    /** @return array<int,string> */
    public function requiredHeaders(): array;
    /** @param array<string,string> $data @param array<string,mixed> $context */
    public function handle(array $data, array $context): array;
}
