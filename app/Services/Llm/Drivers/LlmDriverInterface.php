<?php

namespace App\Services\Llm\Drivers;

interface LlmDriverInterface
{
    /**
     * Send a completion request to the LLM.
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * Get the current model name.
     */
    public function getModel(): string;
}
