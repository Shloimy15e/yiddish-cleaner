<?php

namespace Tests\Unit\Services\Asr;

use App\Services\Asr\AsrWord;
use App\Services\Asr\Drivers\YiddishLabsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class YiddishLabsTimestampParserTest extends TestCase
{
    protected YiddishLabsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create driver with dummy API key for testing
        $this->driver = new YiddishLabsDriver('test-api-key', 'yiddish-libre');
    }

    /**
     * Helper to call protected parseTimestampedText method.
     */
    protected function parseTimestampedText(string $text): ?array
    {
        $method = new ReflectionMethod(YiddishLabsDriver::class, 'parseTimestampedText');
        $method->setAccessible(true);
        
        return $method->invoke($this->driver, $text);
    }

    /**
     * Helper to call protected parseWithBracketTimestamps method.
     */
    protected function parseWithBracketTimestamps(string $text): array
    {
        $method = new ReflectionMethod(YiddishLabsDriver::class, 'parseWithBracketTimestamps');
        $method->setAccessible(true);
        
        return $method->invoke($this->driver, $text);
    }

    /**
     * Helper to call protected parseWithAngleBracketTimestamps method.
     */
    protected function parseWithAngleBracketTimestamps(string $text): array
    {
        $method = new ReflectionMethod(YiddishLabsDriver::class, 'parseWithAngleBracketTimestamps');
        $method->setAccessible(true);
        
        return $method->invoke($this->driver, $text);
    }

    /**
     * Helper to call protected parseWithParenTimestamps method.
     */
    protected function parseWithParenTimestamps(string $text): array
    {
        $method = new ReflectionMethod(YiddishLabsDriver::class, 'parseWithParenTimestamps');
        $method->setAccessible(true);
        
        return $method->invoke($this->driver, $text);
    }

    // ==================== Bracket Timestamp Tests [HH:MM:SS.mmm] ====================

    public function test_parses_bracket_timestamp_format_with_hours(): void
    {
        $text = '[01:23:45.678] שלום עולם [01:23:47.000] טעסט';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(3, $words);
        
        // First word
        $this->assertEquals('שלום', $words[0]->word);
        $this->assertEqualsWithDelta(5025.678, $words[0]->start, 0.001); // 1*3600 + 23*60 + 45 + 0.678
        
        // Second word
        $this->assertEquals('עולם', $words[1]->word);
        
        // Third word
        $this->assertEquals('טעסט', $words[2]->word);
        $this->assertEqualsWithDelta(5027.0, $words[2]->start, 0.001);
    }

    public function test_parses_bracket_timestamp_format_without_hours(): void
    {
        $text = '[00:01.234] word1 [00:02.500] word2';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(2, $words);
        $this->assertEquals('word1', $words[0]->word);
        $this->assertEqualsWithDelta(1.234, $words[0]->start, 0.001);
        $this->assertEquals('word2', $words[1]->word);
        $this->assertEqualsWithDelta(2.5, $words[1]->start, 0.001);
    }

    public function test_distributes_time_evenly_for_multiple_words_in_segment(): void
    {
        $text = '[00:00.000] word1 word2 word3 [00:03.000] word4';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(4, $words);
        
        // First three words should share 3 seconds (1 second each)
        $this->assertEqualsWithDelta(0.0, $words[0]->start, 0.001);
        $this->assertEqualsWithDelta(1.0, $words[0]->end, 0.001);
        
        $this->assertEqualsWithDelta(1.0, $words[1]->start, 0.001);
        $this->assertEqualsWithDelta(2.0, $words[1]->end, 0.001);
        
        $this->assertEqualsWithDelta(2.0, $words[2]->start, 0.001);
        $this->assertEqualsWithDelta(3.0, $words[2]->end, 0.001);
    }

    public function test_estimates_end_time_for_last_segment(): void
    {
        $text = '[00:00.000] word1 word2';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(2, $words);
        
        // Each word should get ~0.3 seconds estimated
        $this->assertEqualsWithDelta(0.0, $words[0]->start, 0.001);
        $this->assertEqualsWithDelta(0.3, $words[0]->end, 0.001);
        
        $this->assertEqualsWithDelta(0.3, $words[1]->start, 0.001);
        $this->assertEqualsWithDelta(0.6, $words[1]->end, 0.001);
    }

    // ==================== Angle Bracket Timestamp Tests <seconds> ====================

    public function test_parses_angle_bracket_timestamp_format(): void
    {
        $text = '<0.000> hello <0.500> world <1.200> test';
        
        $words = $this->parseWithAngleBracketTimestamps($text);
        
        $this->assertCount(3, $words);
        
        $this->assertEquals('hello', $words[0]->word);
        $this->assertEqualsWithDelta(0.0, $words[0]->start, 0.001);
        $this->assertEqualsWithDelta(0.5, $words[0]->end, 0.001);
        
        $this->assertEquals('world', $words[1]->word);
        $this->assertEqualsWithDelta(0.5, $words[1]->start, 0.001);
        $this->assertEqualsWithDelta(1.2, $words[1]->end, 0.001);
    }

    public function test_angle_bracket_handles_integer_seconds(): void
    {
        $text = '<0> word1 <1> word2 <2> word3';
        
        $words = $this->parseWithAngleBracketTimestamps($text);
        
        $this->assertCount(3, $words);
        $this->assertEqualsWithDelta(0.0, $words[0]->start, 0.001);
        $this->assertEqualsWithDelta(1.0, $words[1]->start, 0.001);
        $this->assertEqualsWithDelta(2.0, $words[2]->start, 0.001);
    }

    // ==================== Parenthesis Timestamp Tests (seconds) ====================

    public function test_parses_paren_timestamp_format(): void
    {
        $text = '(0.0) first (1.5) second (3.0) third';
        
        $words = $this->parseWithParenTimestamps($text);
        
        $this->assertCount(3, $words);
        
        $this->assertEquals('first', $words[0]->word);
        $this->assertEqualsWithDelta(0.0, $words[0]->start, 0.001);
        $this->assertEqualsWithDelta(1.5, $words[0]->end, 0.001);
        
        $this->assertEquals('second', $words[1]->word);
        $this->assertEqualsWithDelta(1.5, $words[1]->start, 0.001);
    }

    // ==================== Auto-detection Tests ====================

    public function test_auto_detects_bracket_format(): void
    {
        $text = '[00:00.500] שלום [00:01.000] עולם';
        
        $words = $this->parseTimestampedText($text);
        
        $this->assertNotNull($words);
        $this->assertCount(2, $words);
        $this->assertEquals('שלום', $words[0]->word);
    }

    public function test_auto_detects_angle_bracket_format(): void
    {
        $text = '<0.5> hello <1.0> world';
        
        $words = $this->parseTimestampedText($text);
        
        $this->assertNotNull($words);
        $this->assertCount(2, $words);
        $this->assertEquals('hello', $words[0]->word);
    }

    public function test_auto_detects_paren_format(): void
    {
        $text = '(0.5) hello (1.0) world';
        
        $words = $this->parseTimestampedText($text);
        
        $this->assertNotNull($words);
        $this->assertCount(2, $words);
    }

    public function test_returns_null_for_text_without_timestamps(): void
    {
        $text = 'שלום עולם this is plain text without timestamps';
        
        $words = $this->parseTimestampedText($text);
        
        $this->assertNull($words);
    }

    // ==================== Edge Cases ====================

    public function test_handles_empty_string(): void
    {
        $words = $this->parseTimestampedText('');
        
        $this->assertNull($words);
    }

    public function test_handles_text_with_only_timestamps(): void
    {
        // This should return empty array since there's no text between timestamps
        $text = '[00:00.000][00:01.000]';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(0, $words);
    }

    public function test_confidence_is_always_null_for_yiddishlabs(): void
    {
        $text = '[00:00.500] שלום [00:01.000] עולם';
        
        $words = $this->parseTimestampedText($text);
        
        foreach ($words as $word) {
            $this->assertNull($word->confidence);
        }
    }

    public function test_words_are_asr_word_instances(): void
    {
        $text = '[00:00.500] test';
        
        $words = $this->parseTimestampedText($text);
        
        $this->assertContainsOnlyInstancesOf(AsrWord::class, $words);
    }

    public function test_handles_yiddish_rtl_text(): void
    {
        $text = '[00:00.000] אין דער אנהייב [00:02.000] האט גאט באשאפן';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        // Should properly split RTL text
        $this->assertCount(6, $words);
        $this->assertEquals('אין', $words[0]->word);
        $this->assertEquals('דער', $words[1]->word);
        $this->assertEquals('אנהייב', $words[2]->word);
    }

    public function test_handles_mixed_punctuation(): void
    {
        $text = '[00:00.000] שלום, [00:01.000] עולם!';
        
        $words = $this->parseWithBracketTimestamps($text);
        
        $this->assertCount(2, $words);
        $this->assertEquals('שלום,', $words[0]->word); // Punctuation stays attached
        $this->assertEquals('עולם!', $words[1]->word);
    }
}
