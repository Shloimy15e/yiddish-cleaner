<?php

namespace Database\Factories;

use App\Models\Transcription;
use App\Models\TranscriptionWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TranscriptionWord>
 */
class TranscriptionWordFactory extends Factory
{
    protected $model = TranscriptionWord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $index = 0;
        $index++;
        
        $startTime = ($index - 1) * 0.5;
        $endTime = $startTime + 0.5;

        return [
            'transcription_id' => Transcription::factory(),
            'word_index' => $index,
            'word' => $this->faker->word(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'confidence' => $this->faker->optional(0.8)->randomFloat(4, 0.3, 1.0),
            'corrected_word' => null,
            'is_deleted' => false,
            'is_inserted' => false,
            'corrected_by' => null,
            'corrected_at' => null,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (TranscriptionWord $word) {
            // Reset index counter for new transcription context
        });
    }

    /**
     * Mark the word as corrected.
     */
    public function corrected(string $correction = null): static
    {
        return $this->state(fn (array $attributes) => [
            'corrected_word' => $correction ?? $this->faker->word(),
            'corrected_at' => now(),
        ]);
    }

    /**
     * Mark the word as deleted.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_deleted' => true,
            'corrected_at' => now(),
        ]);
    }

    /**
     * Mark the word as inserted.
     */
    public function inserted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_inserted' => true,
            'confidence' => null,
            'corrected_at' => now(),
        ]);
    }

    /**
     * Set a specific confidence value.
     */
    public function withConfidence(float $confidence): static
    {
        return $this->state(fn (array $attributes) => [
            'confidence' => $confidence,
        ]);
    }

    /**
     * Set low confidence (below threshold).
     */
    public function lowConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'confidence' => $this->faker->randomFloat(4, 0.1, 0.5),
        ]);
    }

    /**
     * Set high confidence (above threshold).
     */
    public function highConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'confidence' => $this->faker->randomFloat(4, 0.85, 1.0),
        ]);
    }

    /**
     * Generate Yiddish-like word.
     */
    public function yiddish(): static
    {
        $yiddishWords = [
            'שלום', 'עולם', 'גאט', 'מענטש', 'לעבן', 'טאג', 'נאכט', 'זון', 'מאמע', 'טאטע',
            'קינד', 'הויז', 'שטאט', 'לאנד', 'וואסער', 'פייער', 'ערד', 'לופט', 'ברויט', 'מילך',
        ];

        return $this->state(fn (array $attributes) => [
            'word' => $this->faker->randomElement($yiddishWords),
        ]);
    }
}
