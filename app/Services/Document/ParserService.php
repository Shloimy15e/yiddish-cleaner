<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class ParserService
{
    protected string $tempDir;
    protected string $libreOfficePath;

    public function __construct()
    {
        $this->tempDir = config('cleaning.temp_dir');
        $this->libreOfficePath = config('cleaning.libreoffice_path');

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Extract text from a document file (docx, doc, txt).
     */
    public function extractText(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt' => file_get_contents($filePath),
            'docx', 'doc' => $this->extractFromWord($filePath),
            default => throw new RuntimeException("Unsupported file type: {$extension}"),
        };
    }

    /**
     * Extract text from Word document using LibreOffice.
     */
    protected function extractFromWord(string $filePath): string
    {
        $outputFile = $this->tempDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.txt';

        // Convert to text using LibreOffice
        $result = Process::timeout(60)->run([
            $this->libreOfficePath,
            '--headless',
            '--convert-to', 'txt:Text',
            '--outdir', $this->tempDir,
            $filePath,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException(
                "LibreOffice conversion failed: " . $result->errorOutput()
            );
        }

        if (!file_exists($outputFile)) {
            throw new RuntimeException("Converted file not found: {$outputFile}");
        }

        $text = file_get_contents($outputFile);

        // Clean up temp file
        @unlink($outputFile);

        return $text;
    }

    /**
     * Save uploaded file to temp directory.
     */
    public function saveUploadedFile($uploadedFile): string
    {
        $filename = uniqid() . '_' . $uploadedFile->getClientOriginalName();
        $path = $this->tempDir . '/' . $filename;
        $uploadedFile->move($this->tempDir, $filename);
        return $path;
    }

    /**
     * Clean up a temp file.
     */
    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath) && str_starts_with($filePath, $this->tempDir)) {
            @unlink($filePath);
        }
    }
}
