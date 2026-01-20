<?php

namespace App\Services\Asr;

interface AsrDriverInterface
{
    /**
     * Transcribe an audio file.
     *
     * @param  string  $audioPath  Path to the audio file
     * @param  array  $options  Provider-specific options
     * @return AsrResult The transcription result
     */
    public function transcribe(string $audioPath, array $options = []): AsrResult;

    /**
     * Get the provider name.
     */
    public function getProvider(): string;

    /**
     * Get the current model name.
     */
    public function getModel(): string;

    /**
     * Check if the driver supports async transcription with polling.
     */
    public function supportsAsync(): bool;
}
