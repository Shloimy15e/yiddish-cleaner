<?php

namespace Database\Factories;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transcription>
 */
class TranscriptionFactory extends Factory
{
    protected $model = Transcription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'audio_sample_id' => AudioSample::factory(),
            'type' => Transcription::TYPE_ASR,
            'name' => $this->faker->sentence(3),
            'source' => Transcription::SOURCE_GENERATED,
            'status' => Transcription::STATUS_COMPLETED,
            'model_name' => 'yiddish_labs',
            'model_version' => '1.0',
            'hypothesis_text' => $this->faker->sentence(10),
            'flagged_for_training' => false,
        ];
    }

    /**
     * Create a base transcription.
     */
    public function base(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Transcription::TYPE_BASE,
            'source' => Transcription::SOURCE_IMPORTED,
            'model_name' => null,
            'model_version' => null,
            'hypothesis_text' => null,
            'text_raw' => $this->faker->sentence(10),
            'text_clean' => $this->faker->sentence(10),
        ]);
    }

    /**
     * Create an ASR transcription.
     */
    public function asr(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Transcription::TYPE_ASR,
            'source' => Transcription::SOURCE_GENERATED,
        ]);
    }

    /**
     * Set transcription to pending status.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcription::STATUS_PENDING,
        ]);
    }

    /**
     * Set transcription to processing status.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcription::STATUS_PROCESSING,
        ]);
    }

    /**
     * Set transcription to completed status.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcription::STATUS_COMPLETED,
        ]);
    }

    /**
     * Set transcription to failed status.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcription::STATUS_FAILED,
            'error_message' => $this->faker->sentence(),
        ]);
    }

    /**
     * Set ASR model to YiddishLabs.
     */
    public function yiddishLabs(): static
    {
        return $this->state(fn (array $attributes) => [
            'model_name' => 'yiddish_labs',
            'model_version' => '1.0',
        ]);
    }

    /**
     * Set ASR model to OpenAI Whisper.
     */
    public function whisper(): static
    {
        return $this->state(fn (array $attributes) => [
            'model_name' => 'whisper',
            'model_version' => 'large-v3',
        ]);
    }

    /**
     * Flag for training.
     */
    public function flaggedForTraining(): static
    {
        return $this->state(fn (array $attributes) => [
            'flagged_for_training' => true,
        ]);
    }

    /**
     * Set specific hypothesis text.
     */
    public function withHypothesis(string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'hypothesis_text' => $text,
        ]);
    }
}
