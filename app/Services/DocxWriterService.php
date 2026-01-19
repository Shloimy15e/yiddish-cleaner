<?php

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Shared\Html;

class DocxWriterService
{
    /**
     * Create a .docx document from cleaned text.
     *
     * @param string $text The cleaned text
     * @param array|null $context Optional context with paragraph metadata
     * @return string The generated .docx file as binary string
     */
    public function createDocument(string $text, ?array $context = null): string
    {
        $phpWord = new PhpWord();

        // Configure default styles for RTL
        $this->configureStyles($phpWord);

        // Create section
        $section = $phpWord->addSection([
            'marginLeft' => 1000,
            'marginRight' => 1000,
            'marginTop' => 1000,
            'marginBottom' => 1000,
        ]);

        // Get paragraph metadata if available
        $paragraphsMeta = $context['paragraphs'] ?? null;

        // Split text into paragraphs
        $paragraphs = explode("\n", $text);

        foreach ($paragraphs as $i => $paraText) {
            $paraText = trim($paraText);
            if (empty($paraText)) {
                // Add empty paragraph for spacing
                $section->addTextBreak();
                continue;
            }

            // Sanitize for XML
            $paraText = $this->sanitizeForXml($paraText);

            // Check if we have metadata for this paragraph
            $meta = null;
            if ($paragraphsMeta && isset($paragraphsMeta[$i])) {
                $meta = $paragraphsMeta[$i];
            }

            // Add paragraph with RTL alignment
            $section->addText(
                $paraText,
                $this->getFontStyle($meta),
                $this->getParagraphStyle($meta),
            );
        }

        // Generate document
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    /**
     * Configure default styles for the document.
     */
    protected function configureStyles(PhpWord $phpWord): void
    {
        // Set default font
        $phpWord->setDefaultFontName('David');
        $phpWord->setDefaultFontSize(12);

        // Define paragraph style for RTL
        $phpWord->addParagraphStyle('RTLParagraph', [
            'alignment' => 'right',
            'bidiVisual' => true,
            'textDirection' => 'rtl',
        ]);

        // Define heading styles
        $phpWord->addFontStyle('Heading1', [
            'name' => 'David',
            'size' => 16,
            'bold' => true,
        ]);
        $phpWord->addFontStyle('Heading2', [
            'name' => 'David',
            'size' => 14,
            'bold' => true,
        ]);
    }

    /**
     * Get font style based on metadata.
     */
    protected function getFontStyle(?array $meta): array
    {
        $style = [
            'name' => 'David',
            'size' => 12,
        ];

        if ($meta) {
            if (isset($meta['font_size'])) {
                $style['size'] = $meta['font_size'];
            }
            if (isset($meta['is_bold']) && $meta['is_bold']) {
                $style['bold'] = true;
            }
        }

        return $style;
    }

    /**
     * Get paragraph style based on metadata.
     */
    protected function getParagraphStyle(?array $meta): array
    {
        $style = [
            'alignment' => 'right',
            'bidiVisual' => true,
            'textDirection' => 'rtl',
            'spaceBefore' => 100,
            'spaceAfter' => 100,
        ];

        return $style;
    }

    /**
     * Sanitize text for XML output.
     */
    protected function sanitizeForXml(string $text): string
    {
        // Remove control characters except newline, carriage return, tab
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Remove zero-width characters
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        return $text;
    }
}
