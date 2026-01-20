<?php

namespace App\Services\Cleaning\Processors;

interface ProcessorInterface
{
    /**
     * Process the text and return cleaned version.
     *
     * @param  string  $text  The text to process
     * @param  array|null  $context  Optional context with paragraph metadata (styles, fonts, etc.)
     */
    public function process(string $text, ?array $context = null): ProcessorResult;

    /**
     * Get the processor name for logging/tracking.
     */
    public function getName(): string;

    /**
     * Get description of what this processor does.
     */
    public function getDescription(): string;
}
