<?php

namespace App\Services\Cleaning\Processors;

/**
 * Processor that removes editorial Hebrew content while keeping spoken Hebrew.
 *
 * In Yiddish transcripts, spoken content naturally contains Hebrew words, pesukim,
 * and religious terminology. This processor distinguishes between:
 *
 * SPOKEN Hebrew (KEEP):
 * - Torah/Tanach quotes (pesukim)
 * - Religious terms (mitzvah, bracha, tefillah)
 * - Sefer names when being quoted in speech
 * - Chassidic phrases and terminology
 *
 * EDITORIAL Hebrew (REMOVE):
 * - Source citations with chapter/verse (ראה שמות כ, ג)
 * - Cross-references (עיין לעיל, ראה שם)
 * - Position markers (לעיל, לקמן, הנ"ל, כנ"ל)
 * - Editor notes (הערה, הערת המתקן)
 */
class EditorialHebrewProcessor implements ProcessorInterface
{
    /**
     * Patterns that indicate EDITORIAL content (not spoken).
     */
    protected array $editorialPatterns = [
        // Cross-references - "see X", "refer to X"
        '/\bראה\s+(?:לעיל|לקמן|שם|הנ"ל|כנ"ל)/u',      // "see above/below/there/aforementioned"
        '/\bעיי?ן\s+(?:לעיל|לקמן|שם|הנ"ל|כנ"ל|ב[\x{05D0}-\x{05EA}]+)/u',  // "refer to above/below/in..."
        '/\bעי[\'׳]\s+[\x{05D0}-\x{05EA}]+/u',        // "see (abbreviation)"
        
        // Position/reference markers
        '/\bלעיל\s+(?:סעיף|אות|פרק|סי[\'׳]|סימן)/u',   // "above section/letter/chapter"
        '/\bלקמן\s+(?:סעיף|אות|פרק|סי[\'׳]|סימן)/u',   // "below section/letter/chapter"
        '/\bכנ"ל\b/u',   // "as above" (abbreviation)
        '/\bהנ"ל\b/u',   // "the aforementioned" (abbreviation)
        '/\bנ"ל\b/u',    // "aforementioned" (abbreviation)
        '/\bוכנ"ל\b/u',  // "and as above"
        '/\bכדלעיל\b/u', // "as above"
        '/\bכדלקמן\b/u', // "as below"
        '/(?<!\S)שם(?=[\s\.,;:]|$)/u',  // "ibid" - standalone or end of sentence
        
        // Source citations with page/chapter references
        '/\bדף\s+[\x{05D0}-\x{05EA}]{1,3}[\'׳]?\s*[עב]?[\'׳]?(?:\s*[-–]\s*[\x{05D0}-\x{05EA}]{1,3}[\'׳]?\s*[עב]?[\'׳]?)?/u',  // "page [gematria] [a/b]"
        '/\bעמ?[\'׳]\s*\d+/u',    // "page [number]"
        '/\bע[\'׳]\s*\d+/u',      // "page [number]" short form
        '/\bפרק\s+[\x{05D0}-\x{05EA}]{1,3}/u',    // "chapter [gematria]"
        '/\bסעיף\s+[\x{05D0}-\x{05EA}]{1,3}/u',   // "section [gematria]"
        '/\bסי[\'׳]מן?\s+[\x{05D0}-\x{05EA}]{1,3}/u', // "siman [gematria]"
        '/\bאות\s+[\x{05D0}-\x{05EA}]{1,3}/u',    // "letter [gematria]"
        '/\bהלכה\s+[\x{05D0}-\x{05EA}]{1,3}/u',   // "halacha [gematria]"
        '/\bמשנה\s+[\x{05D0}-\x{05EA}]{1,3}/u',   // "mishna [gematria]"
        
        // Editor/transcriber notes
        '/\bהערה\b/u',     // "note"
        '/\bהע[\'׳]\b/u',  // "note" abbreviation
        '/\bהערת\s+(?:המתקן|המעתיק|העורך|המהדיר)/u',  // "note of the corrector/transcriber/editor"
        '/\bהוספת\s+(?:המתקן|המעתיק|העורך)/u',        // "addition of the..."
        '/\bתיקון\s+(?:המעתיק|העורך)/u',              // "correction of..."
        
        // Continuation/structural markers
        '/\(המשך\)/u',    // "(continuation)"
        '/\(סיום\)/u',    // "(end)"
        '/\(ראה\s+[^)]+\)/u',   // "(see ...)" in parentheses
        '/\(עיין\s+[^)]+\)/u',  // "(refer to ...)" in parentheses
        '/\(שם\)/u',      // "(ibid)" in parentheses
        '/\(הנ"ל\)/u',    // "(aforementioned)" in parentheses
        '/\(כנ"ל\)/u',    // "(as above)" in parentheses
        
        // Citations in parentheses (book + chapter + verse format)
        '/\([\x{05D0}-\x{05EA}]+\s+[\x{05D0}-\x{05EA}]{1,3}[\'׳]?\s*[,:]?\s*[\x{05D0}-\x{05EA}]{1,3}\)/u',  // (Book ch, v) - gematria
        '/\([\x{05D0}-\x{05EA}]+\s+\d+\s*[,:]\s*\d+\)/u',  // (Book 1:1) - numbers
    ];

    protected bool $removeInlineOnly = true;

    public function process(string $text, ?array $context = null): ProcessorResult
    {
        $removals = [];
        $changesCount = 0;

        foreach ($this->editorialPatterns as $pattern) {
            $text = preg_replace_callback(
                $pattern,
                function ($matches) use (&$removals, &$changesCount, $pattern) {
                    $matched = $matches[0];
                    $removals[] = [
                        'text' => mb_strlen($matched) > 50 ? mb_substr($matched, 0, 50) . '...' : $matched,
                        'full_text' => $matched,
                        'reason' => 'Editorial Hebrew pattern',
                        'processor' => $this->getName(),
                    ];
                    $changesCount++;
                    return '';
                },
                $text
            );
        }

        // Clean up any double spaces left behind
        $text = preg_replace('/  +/', ' ', $text);
        
        // Clean up spaces before punctuation
        $text = preg_replace('/\s+([,\.;:])/u', '$1', $text);

        return new ProcessorResult($text, $removals, $changesCount);
    }

    public function getName(): string
    {
        return 'editorial_hebrew';
    }

    public function getDescription(): string
    {
        return 'Removes editorial Hebrew (citations, references, cross-refs) while keeping spoken Hebrew';
    }
}
