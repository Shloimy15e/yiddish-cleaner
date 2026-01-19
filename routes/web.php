<?php

use App\Http\Controllers\Api\LlmController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProcessController;
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

    // Process
    Route::get('/process', [ProcessController::class, 'index'])->name('process.index');
    Route::post('/process/upload', [ProcessController::class, 'uploadFile'])->name('process.upload');
    Route::post('/process/drive', [ProcessController::class, 'processDrive'])->name('process.drive');
    Route::post('/process/sheet', [ProcessController::class, 'processSheet'])->name('process.sheet');
    Route::get('/process/runs/{run}', [ProcessController::class, 'showRun'])->name('process.run');

    // API - LLM Providers
    Route::get('/api/llm/providers', [LlmController::class, 'providers'])->name('api.llm.providers');
    Route::get('/api/llm/providers/{provider}/models', [LlmController::class, 'models'])->name('api.llm.models');

    // Documents
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/diff', [DocumentController::class, 'diff'])->name('documents.diff');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{document}/download/original', [DocumentController::class, 'downloadOriginal'])->name('documents.download.original');
    Route::get('/documents/{document}/download/text', [DocumentController::class, 'downloadText'])->name('documents.download.text');
    Route::post('/documents/{document}/validate', [DocumentController::class, 'validate'])->name('documents.validate');
    Route::delete('/documents/{document}/validate', [DocumentController::class, 'unvalidate'])->name('documents.unvalidate');

    // Training
    Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
    Route::get('/training/create', [TrainingController::class, 'create'])->name('training.create');
    Route::post('/training', [TrainingController::class, 'store'])->name('training.store');
    Route::get('/training/{version}', [TrainingController::class, 'show'])->name('training.show');
    Route::post('/training/{version}/activate', [TrainingController::class, 'activate'])->name('training.activate');
    Route::get('/training/{version}/export', [TrainingController::class, 'export'])->name('training.export');
    Route::delete('/training/{training}', [TrainingController::class, 'destroy'])->name('training.destroy');
});

require __DIR__.'/settings.php';
