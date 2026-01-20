<?php

use App\Http\Controllers\Api\LlmController;
use App\Http\Controllers\AudioSampleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Audio Samples - CRUD
    Route::get('/audio-samples', [AudioSampleController::class, 'index'])->name('audio-samples.index');
    Route::get('/audio-samples/create', [AudioSampleController::class, 'create'])->name('audio-samples.create');
    Route::post('/audio-samples', [AudioSampleController::class, 'store'])->name('audio-samples.store');
    Route::post('/audio-samples/import', [AudioSampleController::class, 'importSheet'])->name('audio-samples.import');
    Route::get('/audio-samples/runs/{run}', [AudioSampleController::class, 'showRun'])->name('audio-samples.run');
    Route::get('/audio-samples/{audioSample}', [AudioSampleController::class, 'show'])->name('audio-samples.show');
    Route::patch('/audio-samples/{audioSample}', [AudioSampleController::class, 'update'])->name('audio-samples.update');
    Route::delete('/audio-samples/{audioSample}', [AudioSampleController::class, 'destroy'])->name('audio-samples.destroy');
    Route::post('/audio-samples/{audioSample}/clean', [AudioSampleController::class, 'clean'])->name('audio-samples.clean');
    Route::post('/audio-samples/{audioSample}/transcript', [AudioSampleController::class, 'uploadTranscript'])->name('audio-samples.upload-transcript');
    Route::get('/audio-samples/{audioSample}/download', [AudioSampleController::class, 'download'])->name('audio-samples.download');
    Route::get('/audio-samples/{audioSample}/download/original', [AudioSampleController::class, 'downloadOriginal'])->name('audio-samples.download.original');
    Route::get('/audio-samples/{audioSample}/download/text', [AudioSampleController::class, 'downloadText'])->name('audio-samples.download.text');
    Route::post('/audio-samples/{audioSample}/validate', [AudioSampleController::class, 'validate'])->name('audio-samples.validate');
    Route::delete('/audio-samples/{audioSample}/validate', [AudioSampleController::class, 'unvalidate'])->name('audio-samples.unvalidate');

    // Legacy redirects
    Route::get('/process', fn () => redirect()->route('audio-samples.create'))->name('process.legacy');
    Route::get('/import', fn () => redirect()->route('audio-samples.create'))->name('import.legacy');

    // API - LLM Providers
    Route::get('/api/llm/providers', [LlmController::class, 'providers'])->name('api.llm.providers');
    Route::get('/api/llm/providers/{provider}/models', [LlmController::class, 'models'])->name('api.llm.models');

    // Training
    if (config('features.training')) {
        Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
        Route::get('/training/create', [TrainingController::class, 'create'])->name('training.create');
        Route::post('/training', [TrainingController::class, 'store'])->name('training.store');
        Route::get('/training/{version}', [TrainingController::class, 'show'])->name('training.show');
        Route::post('/training/{version}/activate', [TrainingController::class, 'activate'])->name('training.activate');
        Route::get('/training/{version}/export', [TrainingController::class, 'export'])->name('training.export');
        Route::delete('/training/{training}', [TrainingController::class, 'destroy'])->name('training.destroy');
    }
});

require __DIR__.'/settings.php';
