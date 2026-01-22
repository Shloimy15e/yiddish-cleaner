<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\Process;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

class ParserService
{
    protected string $tempDir;

    public function __construct()
    {
        $this->tempDir = config('cleaning.temp_dir');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Extract text from a document file (docx, doc, txt).
     *
     * @param  string  $filePath  Path to the file
     * @param  string|null  $extension  Optional extension override (useful for temp files without extensions)
     */
    public function extractText(string $filePath, ?string $extension = null): string
    {
        $extension = strtolower($extension ?? pathinfo($filePath, PATHINFO_EXTENSION));

        $convertedPath = null;

        if ($extension === 'doc') {
            $convertedPath = $this->convertDocToDocx($filePath);
            $filePath = $convertedPath;
            $extension = 'docx';
        }

        try {
            return match ($extension) {
                'txt' => file_get_contents($filePath),
                'docx' => $this->extractFromDocx($filePath),
                'doc' => $this->extractFromDoc($filePath),
                default => throw new RuntimeException("Unsupported file type: {$extension}"),
            };
        } finally {
            if ($convertedPath && file_exists($convertedPath)) {
                @unlink($convertedPath);
            }
        }
    }

    /**
     * Extract text from .docx using PhpWord (no LibreOffice needed).
     */
    protected function extractFromDocx(string $filePath): string
    {
        try {
            $phpWord = IOFactory::load($filePath, 'Word2007');
            $text = [];

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text[] = $this->extractTextFromElement($element);
                }
            }

            return implode("\n", array_filter($text));
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to parse DOCX: '.$e->getMessage());
        }
    }

    /**
     * Extract text from a PhpWord element recursively.
     */
    protected function extractTextFromElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $elementText = $element->getText();
            if (is_string($elementText)) {
                $text = $elementText;
            } elseif (is_object($elementText) && method_exists($elementText, 'getText')) {
                $text = $elementText->getText();
            }
        }

        if (method_exists($element, 'getElements')) {
            $childTexts = [];
            foreach ($element->getElements() as $child) {
                $childTexts[] = $this->extractTextFromElement($child);
            }
            $text = implode('', array_filter($childTexts));
        }

        return $text;
    }

    /**
     * Extract text from .doc (old Word format).
     * Falls back to basic text extraction.
     */
    protected function extractFromDoc(string $filePath): string
    {
        $convertedPath = $this->convertDocToDocx($filePath);

        try {
            return $this->extractFromDocx($convertedPath);
        } finally {
            if (file_exists($convertedPath)) {
                @unlink($convertedPath);
            }
        }
    }

    /**
     * Convert .doc to .docx using LibreOffice.
     */
    protected function convertDocToDocx(string $filePath): string
    {
        $libreOfficePath = config('cleaning.libreoffice_path', 'soffice');
        $outputDir = dirname($filePath);
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $outputPath = "{$outputDir}/{$baseName}.docx";

        $command = [
            $libreOfficePath,
            '--headless',
            '--convert-to', 'docx',
            '--outdir', $outputDir,
            $filePath,
        ];

        $process = Process::timeout(60)->run($command);

        if (! $process->successful()) {
            throw new RuntimeException('Failed to convert .doc to .docx: '.$process->errorOutput());
        }

        if (! file_exists($outputPath)) {
            throw new RuntimeException("Converted file not found: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Save uploaded file to temp directory.
     */
    public function saveUploadedFile($uploadedFile): string
    {
        $filename = uniqid().'_'.$uploadedFile->getClientOriginalName();
        $path = $this->tempDir.'/'.$filename;
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
