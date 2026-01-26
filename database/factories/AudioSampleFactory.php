<?php

namespace Database\Factories;

use App\Models\AudioSample;
use App\Models\ProcessingRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AudioSample>
 */
class AudioSampleFactory extends Factory
{
    protected $model = AudioSample::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'processing_run_id' => ProcessingRun::factory(),
            'name' => $this->faker->sentence(3),
            'source_url' => $this->faker->optional()->url(),
            'audio_duration_seconds' => $this->faker->randomFloat(2, 1, 120),
            'status' => AudioSample::STATUS_READY,
            'error_message' => null,
        ];
    }

    /**
     * Set status to draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AudioSample::STATUS_DRAFT,
        ]);
    }

    /**
     * Set status to pending_base.
     */
    public function pendingBase(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AudioSample::STATUS_PENDING_BASE,
        ]);
    }

    /**
     * Set status to unclean.
     */
    public function unclean(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AudioSample::STATUS_UNCLEAN,
        ]);
    }

    /**
     * Set status to ready.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AudioSample::STATUS_READY,
        ]);
    }

    /**
     * Set status to benchmarked.
     */
    public function benchmarked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AudioSample::STATUS_BENCHMARKED,
        ]);
    }

    /**
     * Create sample with specific duration.
     */
    public function withDuration(float $seconds): static
    {
        return $this->state(fn (array $attributes) => [
            'audio_duration_seconds' => $seconds,
        ]);
    }
}
