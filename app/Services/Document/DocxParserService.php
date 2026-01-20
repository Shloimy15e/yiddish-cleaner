<?php

namespace App\Services\Document;

use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

/**
 * Service for parsing Word documents with full metadata extraction.
 *
 * Extracts paragraph-level metadata including:
 * - Word heading styles (Heading 1, Title, etc.)
 * - Font sizes
 * - Bold/italic formatting
 * - Text runs with formatting details
 */
class DocxParserService
{
    protected string $tempDir;

    public function __construct()
    {
        $this->tempDir = config('cleaning.temp_dir', storage_path('app/temp'));

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Extract paragraphs with full metadata from a Word document.
     *
     * @param  string  $filePath  Path to the document file
     * @return array{text: string, paragraphs: array} Full text and paragraph metadata
     */
    public function extractWithMetadata(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'doc') {
            $filePath = $this->convertDocToDocx($filePath);
        }

        if ($extension === 'txt') {
            return $this->extractFromTxt($filePath);
        }

        return $this->extractFromDocx($filePath);
    }

    /**
     * Extract paragraphs with metadata from a .docx file.
     */
    protected function extractFromDocx(string $filePath): array
    {
        try {
            $phpWord = IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to load document: {$e->getMessage()}");
        }

        $paragraphsMeta = [];
        $allFontSizes = [];
        $currentPos = 0;

        // Get default font size from document
        $defaultFontSize = $this->getDocumentDefaultFontSize($phpWord);

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $paragraphData = $this->extractParagraphData($element, $defaultFontSize);

                if ($paragraphData === null) {
                    continue;
                }

                $text = $paragraphData['text'];
                if (empty(trim($text))) {
                    continue;
                }

                $paraLen = mb_strlen($text);
                $wordCount = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));

                if ($paragraphData['font_size']) {
                    $allFontSizes[] = $paragraphData['font_size'];
                }

                $paragraphsMeta[] = [
                    'text' => $text,
                    'original_text' => $text,
                    'start_pos' => $currentPos,
                    'end_pos' => $currentPos + $paraLen,
                    'style_name' => $paragraphData['style_name'],
                    'is_heading_style' => $paragraphData['is_heading_style'],
                    'is_bold' => $paragraphData['is_bold'],
                    'font_size' => $paragraphData['font_size'],
                    'char_count' => $paraLen,
                    'word_count' => $wordCount,
                    'runs' => $paragraphData['runs'],
                ];

                $currentPos += $paraLen + 1; // +1 for newline
            }
        }

        // Calculate average font size and mark larger paragraphs
        $avgFontSize = count($allFontSizes) > 0
            ? array_sum($allFontSizes) / count($allFontSizes)
            : $defaultFontSize;

        foreach ($paragraphsMeta as &$meta) {
            $meta['is_larger_than_normal'] = (
                $meta['font_size'] !== null &&
                $meta['font_size'] > $avgFontSize * 1.2
            );
            $meta['avg_font_size'] = $avgFontSize;
        }

        $fullText = implode("\n", array_column($paragraphsMeta, 'text'));

        return [
            'text' => $fullText,
            'paragraphs' => $paragraphsMeta,
        ];
    }

    /**
     * Extract data from a single paragraph element.
     */
    protected function extractParagraphData($element, float $defaultFontSize): ?array
    {
        // Handle TextRun (paragraph with formatting)
        if ($element instanceof TextRun) {
            return $this->extractFromTextRun($element, $defaultFontSize);
        }

        // Handle plain Text elements
        if ($element instanceof Text) {
            $text = $element->getText();
            $fontStyle = $element->getFontStyle();

            return [
                'text' => $text,
                'style_name' => null,
                'is_heading_style' => false,
                'is_bold' => $fontStyle?->isBold() ?? false,
                'font_size' => $fontStyle?->getSize() ?? $defaultFontSize,
                'runs' => [[
                    'text' => $text,
                    'bold' => $fontStyle?->isBold() ?? false,
                    'italic' => $fontStyle?->isItalic() ?? false,
                    'font_size' => $fontStyle?->getSize(),
                ]],
            ];
        }

        // Handle string elements
        if (is_string($element)) {
            return [
                'text' => $element,
                'style_name' => null,
                'is_heading_style' => false,
                'is_bold' => false,
                'font_size' => $defaultFontSize,
                'runs' => [['text' => $element, 'bold' => false, 'italic' => false]],
            ];
        }

        // Check for paragraph-style elements
        if (method_exists($element, 'getElements')) {
            return $this->extractFromContainer($element, $defaultFontSize);
        }

        return null;
    }

    /**
     * Extract data from a TextRun element.
     */
    protected function extractFromTextRun(TextRun $textRun, float $defaultFontSize): array
    {
        $texts = [];
        $runs = [];
        $fontSizes = [];
        $allBold = true;
        $hasText = false;

        // Get paragraph style
        $paragraphStyle = $textRun->getParagraphStyle();
        $styleName = null;
        $isHeadingStyle = false;

        if ($paragraphStyle) {
            if (is_string($paragraphStyle)) {
                $styleName = $paragraphStyle;
            } elseif (method_exists($paragraphStyle, 'getStyleName')) {
                $styleName = $paragraphStyle->getStyleName();
            }
        }

        if ($styleName) {
            $styleNameLower = strtolower($styleName);
            $isHeadingStyle = str_contains($styleNameLower, 'heading')
                || str_contains($styleNameLower, 'title')
                || str_contains($styleNameLower, 'כותרת'); // Hebrew "title"
        }

        foreach ($textRun->getElements() as $child) {
            if ($child instanceof Text) {
                $text = $child->getText();
                if (empty($text)) {
                    continue;
                }

                $texts[] = $text;
                $hasText = true;

                $fontStyle = $child->getFontStyle();
                $isBold = $fontStyle?->isBold() ?? false;
                $isItalic = $fontStyle?->isItalic() ?? false;
                $fontSize = $fontStyle?->getSize();

                if ($fontSize) {
                    $fontSizes[] = $fontSize;
                }

                if (! empty(trim($text)) && ! $isBold) {
                    $allBold = false;
                }

                $runs[] = [
                    'text' => $text,
                    'bold' => $isBold,
                    'italic' => $isItalic,
                    'underline' => $fontStyle?->getUnderline() !== null && $fontStyle->getUnderline() !== 'none',
                    'font_size' => $fontSize,
                    'font_name' => $fontStyle?->getName(),
                ];
            } elseif ($child instanceof TextBreak) {
                $texts[] = "\n";
            }
        }

        $fullText = implode('', $texts);
        $avgFontSize = count($fontSizes) > 0
            ? array_sum($fontSizes) / count($fontSizes)
            : $defaultFontSize;

        return [
            'text' => $fullText,
            'style_name' => $styleName,
            'is_heading_style' => $isHeadingStyle,
            'is_bold' => $allBold && $hasText,
            'font_size' => $avgFontSize,
            'runs' => $runs,
        ];
    }

    /**
     * Extract data from a container element (like tables, etc.).
     */
    protected function extractFromContainer($container, float $defaultFontSize): ?array
    {
        $texts = [];
        $runs = [];

        foreach ($container->getElements() as $element) {
            $data = $this->extractParagraphData($element, $defaultFontSize);
            if ($data) {
                $texts[] = $data['text'];
                $runs = array_merge($runs, $data['runs']);
            }
        }

        if (empty($texts)) {
            return null;
        }

        $fullText = implode(' ', $texts);

        return [
            'text' => $fullText,
            'style_name' => null,
            'is_heading_style' => false,
            'is_bold' => false,
            'font_size' => $defaultFontSize,
            'runs' => $runs,
        ];
    }

    /**
     * Get the document's default font size.
     */
    protected function getDocumentDefaultFontSize($phpWord): float
    {
        $defaultFontSize = 12.0;

        try {
            $defaultFontStyle = $phpWord->getDefaultFontStyle();
            if ($defaultFontStyle && $defaultFontStyle->getSize()) {
                $defaultFontSize = (float) $defaultFontStyle->getSize();
            }
        } catch (\Exception $e) {
            // Use default
        }

        return $defaultFontSize;
    }

    /**
     * Extract from plain text file.
     */
    protected function extractFromTxt(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        $paragraphs = [];
        $currentPos = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            $len = mb_strlen($line);
            $wordCount = count(preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY));

            $paragraphs[] = [
                'text' => $line,
                'original_text' => $line,
                'start_pos' => $currentPos,
                'end_pos' => $currentPos + $len,
                'style_name' => null,
                'is_heading_style' => false,
                'is_bold' => false,
                'font_size' => 12.0,
                'char_count' => $len,
                'word_count' => $wordCount,
                'runs' => [['text' => $line, 'bold' => false, 'italic' => false]],
                'is_larger_than_normal' => false,
                'avg_font_size' => 12.0,
            ];

            $currentPos += $len + 1;
        }

        return [
            'text' => $content,
            'paragraphs' => $paragraphs,
        ];
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

        $process = \Illuminate\Support\Facades\Process::timeout(60)->run($command);

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
