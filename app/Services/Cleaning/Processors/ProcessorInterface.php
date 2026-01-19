<?php

namespace App\Services\Cleaning\Processors;

interface ProcessorInterface
{
    /**
     * Process the text and return cleaned version.
     */
    public function process(string $text): ProcessorResult;

    /**
     * Get the processor name for logging/tracking.
     */
    public function getName(): string;

    /**
     * Get description of what this processor does.
     */
    public function getDescription(): string;
}
