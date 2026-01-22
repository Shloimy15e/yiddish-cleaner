<?php

use App\Http\Controllers\Api\AsrController;
use App\Http\Controllers\Api\LlmController;
use App\Http\Controllers\AudioSampleController;
use App\Http\Controllers\BenchmarkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\ProcessingRunController;
use App\Http\Controllers\TranscriptionController;
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
    Route::get('/audio-samples/runs', [ProcessingRunController::class, 'index'])->name('audio-samples.runs');
    Route::get('/audio-samples/create', [AudioSampleController::class, 'create'])->name('audio-samples.create');
    Route::post('/audio-samples', [AudioSampleController::class, 'store'])->name('audio-samples.store');
    Route::post('/audio-samples/import', [AudioSampleController::class, 'importSheet'])->name('audio-samples.import');
    Route::get('/audio-samples/runs/{run}', [ProcessingRunController::class, 'show'])->name('audio-samples.run');
    Route::get('/audio-samples/{audioSample}', [AudioSampleController::class, 'show'])->name('audio-samples.show');
    Route::patch('/audio-samples/{audioSample}', [AudioSampleController::class, 'update'])->name('audio-samples.update');
    Route::post('/audio-samples/{audioSample}/clean', [AudioSampleController::class, 'clean'])->name('audio-samples.clean');
    Route::post('/audio-samples/bulk-clean', [AudioSampleController::class, 'bulkClean'])->name('audio-samples.bulk-clean');
    Route::delete('/audio-samples/bulk-delete', [AudioSampleController::class, 'bulkDelete'])->name('audio-samples.bulk-delete');
    Route::delete('/audio-samples/{audioSample}', [AudioSampleController::class, 'destroy'])->name('audio-samples.destroy');
    Route::post('/audio-samples/{audioSample}/transcript', [AudioSampleController::class, 'uploadTranscript'])->name('audio-samples.upload-transcript');
    Route::post('/audio-samples/{audioSample}/audio', [AudioSampleController::class, 'uploadAudio'])->name('audio-samples.upload-audio');
    Route::get('/audio-samples/{audioSample}/download', [AudioSampleController::class, 'download'])->name('audio-samples.download');
    Route::get('/audio-samples/{audioSample}/download/original', [AudioSampleController::class, 'downloadOriginal'])->name('audio-samples.download.original');
    Route::get('/audio-samples/{audioSample}/download/text', [AudioSampleController::class, 'downloadText'])->name('audio-samples.download.text');
    Route::post('/audio-samples/{audioSample}/validate', [AudioSampleController::class, 'validate'])->name('audio-samples.validate');
    Route::delete('/audio-samples/{audioSample}/validate', [AudioSampleController::class, 'unvalidate'])->name('audio-samples.unvalidate');

    // ASR Transcription
    Route::post('/audio-samples/{audioSample}/transcribe', [AudioSampleController::class, 'transcribe'])->name('audio-samples.transcribe');
    Route::post('/audio-samples/bulk-transcribe', [AudioSampleController::class, 'bulkTranscribe'])->name('audio-samples.bulk-transcribe');

    // ==================== Base Transcriptions ====================
    Route::get('/transcriptions', [TranscriptionController::class, 'index'])->name('transcriptions.index');
    Route::get('/transcriptions/create', [TranscriptionController::class, 'create'])->name('transcriptions.create');
    Route::post('/transcriptions', [TranscriptionController::class, 'storeBase'])->name('transcriptions.store-base');
    Route::get('/transcriptions/orphan', [TranscriptionController::class, 'orphanList'])->name('transcriptions.orphan-list');
    Route::get('/transcriptions/{transcription}', [TranscriptionController::class, 'show'])->name('transcriptions.show');
    Route::patch('/transcriptions/{transcription}', [TranscriptionController::class, 'update'])->name('transcriptions.update');
    Route::delete('/transcriptions/{transcription}', [TranscriptionController::class, 'destroy'])->name('transcriptions.destroy');
    Route::post('/transcriptions/{transcription}/clean', [TranscriptionController::class, 'clean'])->name('transcriptions.clean');
    Route::post('/transcriptions/{transcription}/validate', [TranscriptionController::class, 'validate'])->name('transcriptions.validate');
    Route::delete('/transcriptions/{transcription}/validate', [TranscriptionController::class, 'unvalidate'])->name('transcriptions.unvalidate');
    Route::post('/transcriptions/{transcription}/link', [TranscriptionController::class, 'linkToAudioSample'])->name('transcriptions.link');
    Route::delete('/transcriptions/{transcription}/link', [TranscriptionController::class, 'unlinkFromAudioSample'])->name('transcriptions.unlink');

    // ==================== ASR Transcriptions (nested under audio samples) ====================
    Route::post('/audio-samples/{audioSample}/transcriptions', [TranscriptionController::class, 'storeAsr'])->name('transcriptions.store-asr');
    Route::post('/audio-samples/{audioSample}/transcriptions/import', [TranscriptionController::class, 'importAsr'])->name('transcriptions.import-asr');
    Route::get('/audio-samples/{audioSample}/transcriptions/{transcription}', [TranscriptionController::class, 'showForAudioSample'])->name('transcriptions.show-for-sample');
    Route::delete('/audio-samples/{audioSample}/transcriptions/{transcription}', [TranscriptionController::class, 'destroyAsr'])->name('transcriptions.destroy-asr');
    Route::post('/audio-samples/{audioSample}/transcriptions/{transcription}/recalculate', [TranscriptionController::class, 'recalculate'])->name('transcriptions.recalculate');

    // Legacy redirects
    Route::get('/process', fn () => redirect()->route('audio-samples.create'))->name('process.legacy');
    Route::get('/import', fn () => redirect()->route('audio-samples.create'))->name('import.legacy');

    // API - LLM Providers
    Route::get('/api/llm/providers', [LlmController::class, 'providers'])->name('api.llm.providers');
    Route::get('/api/llm/providers/{provider}/models', [LlmController::class, 'models'])->name('api.llm.models');

    // API - ASR Providers
    Route::get('/api/asr/providers', [AsrController::class, 'providers'])->name('api.asr.providers');

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

// Public Benchmark Routes (no auth required)
Route::get('/benchmark', [BenchmarkController::class, 'index'])->name('benchmark.index');
Route::get('/benchmark/compare', [BenchmarkController::class, 'compare'])->name('benchmark.compare');
Route::get('/benchmark/models/{modelName}', [BenchmarkController::class, 'model'])->name('benchmark.model')->where('modelName', '.*');

require __DIR__.'/settings.php';
