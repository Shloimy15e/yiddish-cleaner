<script setup lang="ts">
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Documentation" />
    
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Header -->
        <header class="bg-white dark:bg-gray-800 shadow">
            <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        Getting Started
                    </h1>
                    <Link
                        href="/dashboard"
                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <ArrowLeftIcon class="h-4 w-4" />
                        Back
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
            
            <!-- What is this? -->
            <section class="prose prose-indigo dark:prose-invert max-w-none">
                <p class="text-lg text-gray-600 dark:text-gray-300">
                    Benchmark ASR (speech-to-text) models on Yiddish audio. Import audio with reference transcripts, 
                    then compare how well different ASR models perform.
                </p>
            </section>

            <!-- Quick Start -->
            <section class="rounded-lg border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-6">
                <h2 class="text-lg font-semibold text-indigo-900 dark:text-indigo-100 mb-4">Quick Start</h2>
                <ol class="space-y-2 text-indigo-800 dark:text-indigo-200">
                    <li><strong>1.</strong> Go to <strong>Import</strong> → upload audio + transcript</li>
                    <li><strong>2.</strong> Open the <strong>Transcription</strong> → click <strong>Clean</strong> → then <strong>Validate</strong></li>
                    <li><strong>3.</strong> Open the <strong>Audio Sample</strong> → run ASR → see results</li>
                </ol>
            </section>

            <!-- Core Workflow -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">How It Works</h2>
                <div class="space-y-3 text-gray-600 dark:text-gray-300">
                    <p>
                        <strong>Audio Samples</strong> hold your audio files. Each one can have a 
                        <strong>Base Transcription</strong> (the correct reference text) and multiple 
                        <strong>ASR Transcriptions</strong> (machine outputs to benchmark).
                    </p>
                    <p>
                        Before benchmarking, the base transcription must be <strong>cleaned</strong> (remove formatting) 
                        and <strong>validated</strong> (you confirm it's correct).
                    </p>
                </div>
            </section>

            <!-- Statuses -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Audio Sample Statuses</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex w-28 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/30 px-2 py-1 text-xs font-medium text-yellow-700 dark:text-yellow-300">pending_base</span>
                        <span class="text-gray-600 dark:text-gray-400">Needs a transcription linked</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex w-28 items-center justify-center rounded-full bg-orange-100 dark:bg-orange-900/30 px-2 py-1 text-xs font-medium text-orange-700 dark:text-orange-300">unclean</span>
                        <span class="text-gray-600 dark:text-gray-400">Has transcription, needs cleaning/validation</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex w-28 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-300">ready</span>
                        <span class="text-gray-600 dark:text-gray-400">Ready for ASR benchmarking</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex w-28 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30 px-2 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-300">benchmarked</span>
                        <span class="text-gray-600 dark:text-gray-400">Has ASR results</span>
                    </div>
                </div>
            </section>

            <!-- Cleaning Presets -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Cleaning Presets</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                    Choose based on your text format:
                </p>
                <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                    <li><code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">titles_only</code> — Removes headings, keeps brackets (5710-5711)</li>
                    <li><code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">full_clean</code> — Removes headings + brackets (5712+, most common)</li>
                    <li><code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">heavy</code> — Aggressive cleaning for heavily formatted text</li>
                    <li><code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">minimal</code> — Just whitespace cleanup</li>
                </ul>
            </section>

            <!-- Metrics -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Metrics</h2>
                <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                    <li><strong>WER</strong> (Word Error Rate) — % of words wrong. Lower = better.</li>
                    <li><strong>CER</strong> (Character Error Rate) — % of characters wrong. More granular.</li>
                    <li><strong>Clean Rate</strong> — % of text kept after cleaning. Very low = check the preset.</li>
                </ul>
            </section>

            <!-- Setup -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Setup</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Go to <Link href="/settings/credentials" class="text-indigo-600 hover:underline">Settings → Credentials</Link> 
                    to add API keys for LLM cleaning and ASR providers.
                </p>
            </section>

        </main>
    </div>
</template>
