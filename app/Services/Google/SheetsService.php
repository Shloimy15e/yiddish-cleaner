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

        $response = $this->service->spreadsheets_values->get($spreadsheetId, $range);

        return $response->getValues() ?? [];
    }

    /**
     * Get all rows with headers as keys.
     */
    public function getRowsWithHeaders(string $spreadsheetId, string $sheetName = 'Sheet1'): array
    {
        $values = $this->getValues($spreadsheetId, $sheetName);

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

        $range = "{$sheetName}!A{$rowIndex}";

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
        // Get headers to find column positions
        $headers = $this->getValues($spreadsheetId, "{$sheetName}!1:1")[0] ?? [];

        foreach ($columnValues as $column => $value) {
            $colIndex = array_search($column, $headers);
            if ($colIndex !== false) {
                $colLetter = $this->columnIndexToLetter($colIndex);
                $this->updateCell($spreadsheetId, "{$sheetName}!{$colLetter}{$rowIndex}", $value);
            }
        }
    }

    /**
     * Find column index for a header name.
     */
    public function findColumn(string $spreadsheetId, string $sheetName, string $headerName): ?int
    {
        $headers = $this->getValues($spreadsheetId, "{$sheetName}!1:1")[0] ?? [];
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

    protected function ensureService(): void
    {
        if (! $this->service) {
            throw new RuntimeException('Sheets service not initialized. Call forUser() first.');
        }
    }
}
