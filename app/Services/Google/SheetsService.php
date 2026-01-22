<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

class SheetsService
{
    protected ?Sheets $service = null;

    public function __construct(
        protected GoogleAuthService $authService,
    ) {}

    /**
     * Initialize the Sheets service for a user.
     */
    public function forUser(User $user): self
    {
        $client = $this->authService->getClientForUser($user);

        if (! $client) {
            throw new RuntimeException('User does not have valid Google credentials');
        }

        $this->service = new Sheets($client);

        return $this;
    }

    /**
     * Read values from a sheet.
     */
    public function getValues(string $spreadsheetId, string $range): array
    {
        $this->ensureService();

        $response = $this->service->spreadsheets_values->get(
            $spreadsheetId,
            $this->normalizeRange($range)
        );

        return $response->getValues() ?? [];
    }

    /**
     * Get all rows with headers as keys.
     */
    public function getRowsWithHeaders(string $spreadsheetId, string $sheetName = ''): array
    {
        $resolvedSheetName = $this->resolveSheetName($spreadsheetId, $sheetName);
        $values = $this->getValues(
            $spreadsheetId,
            $this->buildRange($resolvedSheetName, 'A1:ZZ')
        );

        if (empty($values)) {
            return [];
        }

        $headers = array_shift($values);
        $rows = [];

        foreach ($values as $index => $row) {
            $rowData = ['_row_index' => $index + 2]; // 1-indexed, accounting for header
            foreach ($headers as $i => $header) {
                $rowData[$header] = $row[$i] ?? '';
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * Update a single cell.
     */
    public function updateCell(string $spreadsheetId, string $cell, $value): void
    {
        $this->ensureService();

        $body = new ValueRange([
            'values' => [[$value]],
        ]);

        $this->service->spreadsheets_values->update(
            $spreadsheetId,
            $cell,
            $body,
            ['valueInputOption' => 'RAW']
        );
    }

    /**
     * Update a row by index.
     */
    public function updateRow(string $spreadsheetId, string $sheetName, int $rowIndex, array $values): void
    {
        $this->ensureService();

        $resolvedSheetName = $this->resolveSheetName($spreadsheetId, $sheetName);
        $range = $this->buildRange($resolvedSheetName, "A{$rowIndex}");

        $body = new ValueRange([
            'values' => [array_values($values)],
        ]);

        $this->service->spreadsheets_values->update(
            $spreadsheetId,
            $range,
            $body,
            ['valueInputOption' => 'RAW']
        );
    }

    /**
     * Update specific columns in a row.
     */
    public function updateColumns(string $spreadsheetId, string $sheetName, int $rowIndex, array $columnValues): void
    {
        $resolvedSheetName = $this->resolveSheetName($spreadsheetId, $sheetName);
        // Get headers to find column positions
        $headers = $this->getValues($spreadsheetId, $this->buildRange($resolvedSheetName, '1:1'))[0] ?? [];

        foreach ($columnValues as $column => $value) {
            $colIndex = array_search($column, $headers);
            if ($colIndex !== false) {
                $colLetter = $this->columnIndexToLetter($colIndex);
                $this->updateCell(
                    $spreadsheetId,
                    $this->buildRange($resolvedSheetName, "{$colLetter}{$rowIndex}"),
                    $value
                );
            }
        }
    }

    /**
     * Find column index for a header name.
     */
    public function findColumn(string $spreadsheetId, string $sheetName, string $headerName): ?int
    {
        $resolvedSheetName = $this->resolveSheetName($spreadsheetId, $sheetName);
        $headers = $this->getValues($spreadsheetId, $this->buildRange($resolvedSheetName, '1:1'))[0] ?? [];
        $index = array_search($headerName, $headers);

        return $index !== false ? $index : null;
    }

    /**
     * Extract spreadsheet ID from URL.
     */
    public static function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert column index to letter (0 = A, 1 = B, etc.)
     */
    protected function columnIndexToLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    protected function buildRange(string $sheetName, ?string $suffix = null): string
    {
        $escaped = str_replace("'", "''", $sheetName);
        $quoted = "'{$escaped}'";

        if (! $suffix) {
            return $sheetName;
        }

        return "{$quoted}!{$suffix}";
    }

    protected function normalizeRange(string $range): string
    {
        $trimmed = trim($range);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_contains($trimmed, '!')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'")) {
            return $trimmed;
        }

        return $this->buildRange($trimmed);
    }

    protected function resolveSheetName(string $spreadsheetId, string $sheetName): string
    {
        $name = trim($sheetName);

        $this->ensureService();

        $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
        $sheets = $spreadsheet->getSheets();

        $titles = [];
        foreach ($sheets as $sheet) {
            $title = $sheet?->getProperties()?->getTitle();
            if ($title !== null && $title !== '') {
                $titles[] = $title;
            }
        }

        $firstTitle = $titles[0] ?? null;

        if (! $firstTitle) {
            throw new RuntimeException('Spreadsheet has no sheets to import.');
        }

        if ($name === '' || $name === 'Sheet1') {
            return $firstTitle;
        }

        if (in_array($name, $titles, true)) {
            return $name;
        }

        throw new RuntimeException("Sheet '{$name}' not found in spreadsheet.");
    }

    protected function ensureService(): void
    {
        if (! $this->service) {
            throw new RuntimeException('Sheets service not initialized. Call forUser() first.');
        }
    }
}
