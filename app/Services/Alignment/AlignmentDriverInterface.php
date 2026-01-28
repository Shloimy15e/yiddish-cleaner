<?php

namespace App\Services\Alignment;

/**
 * Interface for forced alignment model drivers.
 * 
 * Alignment drivers take audio and text as input and return
 * word-level timing information by aligning the text to the audio.
 */
interface AlignmentDriverInterface
{
    /**
     * Align text to audio, generating word-level timing data.
     *
     * @param  string  $audioPath  Path to the audio file
     * @param  string  $text  Text to align to the audio
     * @param  array  $options  Provider-specific options
     * @return AlignmentResult The alignment result with word timings
     */
    public function align(string $audioPath, string $text, array $options = []): AlignmentResult;

    /**
     * Get the provider name.
     */
    public function getProvider(): string;

    /**
     * Get the current model name.
     */
    public function getModel(): string;

    /**
     * Check if the driver supports async alignment with polling.
     */
    public function supportsAsync(): bool;

    /**
     * Check if the driver supports batch alignment.
     */
    public function supportsBatch(): bool;
}
