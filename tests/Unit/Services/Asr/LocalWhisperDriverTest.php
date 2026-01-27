<?php

namespace Tests\Unit\Services\Asr;

use App\Services\Asr\Drivers\LocalWhisperDriver;
use Tests\TestCase;

class LocalWhisperDriverTest extends TestCase
{
    public function test_driver_returns_correct_provider_name(): void
    {
        $driver = new LocalWhisperDriver(model: 'tiny');

        $this->assertEquals('local-whisper', $driver->getProvider());
    }

    public function test_driver_returns_model_name(): void
    {
        $driver = new LocalWhisperDriver(model: 'base');

        $this->assertEquals('base', $driver->getModel());
    }

    public function test_driver_does_not_support_async(): void
    {
        $driver = new LocalWhisperDriver(model: 'tiny');

        $this->assertFalse($driver->supportsAsync());
    }

    public function test_driver_accepts_different_models(): void
    {
        $models = ['tiny', 'base', 'small', 'medium', 'large', 'turbo'];

        foreach ($models as $model) {
            $driver = new LocalWhisperDriver(model: $model);
            $this->assertEquals($model, $driver->getModel());
        }
    }

    public function test_model_mapping(): void
    {
        // Check that the MODELS constant maps short names to full model names
        $this->assertEquals('tiny', LocalWhisperDriver::MODELS['tiny']);
        $this->assertEquals('base', LocalWhisperDriver::MODELS['base']);
        $this->assertEquals('small', LocalWhisperDriver::MODELS['small']);
        $this->assertEquals('medium', LocalWhisperDriver::MODELS['medium']);
        $this->assertEquals('large-v3', LocalWhisperDriver::MODELS['large']);
        $this->assertEquals('large-v3-turbo', LocalWhisperDriver::MODELS['turbo']);
    }

    public function test_throws_exception_for_missing_file(): void
    {
        $driver = new LocalWhisperDriver(model: 'tiny');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audio file not found');

        $driver->transcribe('/nonexistent/file.wav');
    }
}
