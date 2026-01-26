<?php

namespace Database\Factories;

use App\Models\ProcessingRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProcessingRun>
 */
class ProcessingRunFactory extends Factory
{
    protected $model = ProcessingRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'batch_id' => (string) Str::uuid(),
            'preset' => 'standard',
            'mode' => 'auto',
            'source_type' => 'manual',
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'status' => 'pending',
        ];
    }

    /**
     * Set status to processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
        ]);
    }

    /**
     * Set status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Set status to failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
