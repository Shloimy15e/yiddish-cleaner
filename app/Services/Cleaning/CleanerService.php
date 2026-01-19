<?php

namespace App\Services\Cleaning;

use App\Services\Cleaning\Processors\ProcessorInterface;
use App\Services\Cleaning\Processors\ProcessorResult;
use InvalidArgumentException;

class CleanerService
{
    protected array $processorInstances = [];

    /**
     * Clean text using a preset configuration.
     */
    public function cleanWithPreset(string $text, string $presetName): CleaningResult
    {
        $preset = config("cleaning.presets.{$presetName}");

        if (!$preset) {
            throw new InvalidArgumentException("Unknown preset: {$presetName}");
        }

        return $this->clean($text, $preset['processors']);
    }

    /**
     * Clean text using a specific list of processors.
     */
    public function clean(string $text, array $processorNames): CleaningResult
    {
        $originalText = $text;
        $allRemovals = [];
        $processorResults = [];

        foreach ($processorNames as $name) {
            $processor = $this->getProcessor($name);
            $result = $processor->process($text);

            $text = $result->text;
            $allRemovals = array_merge($allRemovals, $result->removals);
            $processorResults[$name] = [
                'changes' => $result->changesCount,
                'removals' => $result->removals,
            ];
        }

        return new CleaningResult(
            originalText: $originalText,
            cleanedText: $text,
            removals: $allRemovals,
            processorResults: $processorResults,
        );
    }

    /**
     * Get or create a processor instance.
     */
    protected function getProcessor(string $name): ProcessorInterface
    {
        if (!isset($this->processorInstances[$name])) {
            $class = config("cleaning.processors.{$name}");

            if (!$class) {
                throw new InvalidArgumentException("Unknown processor: {$name}");
            }

            $this->processorInstances[$name] = app($class);
        }

        return $this->processorInstances[$name];
    }

    /**
     * Get available presets.
     */
    public function getPresets(): array
    {
        return config('cleaning.presets', []);
    }

    /**
     * Get available processors.
     */
    public function getProcessors(): array
    {
        $processors = [];
        foreach (config('cleaning.processors', []) as $name => $class) {
            $instance = $this->getProcessor($name);
            $processors[$name] = [
                'name' => $instance->getName(),
                'description' => $instance->getDescription(),
            ];
        }
        return $processors;
    }
}
