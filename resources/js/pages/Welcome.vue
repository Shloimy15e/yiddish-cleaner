<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Yiddish ASR Benchmark">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    
    <div class="min-h-screen bg-[#0a0a0f]">
        <!-- Navigation -->
        <header class="container mx-auto px-6 py-6">
            <nav class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-[#00d4ff] to-[#ff006e]">
                        <AppLogoIcon class="h-5 w-5 text-white" />
                    </div>
                    <span class="text-xl font-bold text-[#f5f5f7]">Yiddish ASR Benchmark</span>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-lg bg-[#00d4ff] px-5 py-2 font-medium text-[#0a0a0f] hover:shadow-[0_0_20px_rgba(0,212,255,0.3)] transition-all"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="px-5 py-2 font-medium text-[#a8a8b8] hover:text-[#f5f5f7] transition-colors"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-lg bg-[#00d4ff] px-5 py-2 font-medium text-[#0a0a0f] hover:shadow-[0_0_20px_rgba(0,212,255,0.3)] transition-all"
                        >
                            Get Started
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <!-- Hero Section -->
        <main class="container mx-auto px-6 py-16 lg:py-24">
            <div class="mx-auto max-w-4xl text-center">
                <h1 class="text-4xl font-bold tracking-tight text-[#f5f5f7] sm:text-6xl">
                    Benchmark Yiddish
                    <span class="bg-gradient-to-r from-[#00d4ff] to-[#ff006e] bg-clip-text text-transparent">ASR Models</span>
                </h1>
                <p class="mt-6 text-lg leading-8 text-[#a8a8b8]">
                    Import audio samples with reference transcriptions, clean and validate them,
                    then benchmark automatic speech recognition models to find the best performer.
                </p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-lg bg-gradient-to-r from-[#00d4ff] to-[#ff006e] px-6 py-3 text-lg font-semibold text-white shadow-[0_0_20px_rgba(0,212,255,0.3),0_0_40px_rgba(255,0,110,0.1)] hover:shadow-[0_0_30px_rgba(0,212,255,0.5),0_0_60px_rgba(255,0,110,0.2)] transition-all hover:-translate-y-0.5"
                    >
                        Go to Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="route('register')"
                        class="rounded-lg bg-gradient-to-r from-[#00d4ff] to-[#ff006e] px-6 py-3 text-lg font-semibold text-white shadow-[0_0_20px_rgba(0,212,255,0.3),0_0_40px_rgba(255,0,110,0.1)] hover:shadow-[0_0_30px_rgba(0,212,255,0.5),0_0_60px_rgba(255,0,110,0.2)] transition-all hover:-translate-y-0.5"
                    >
                        Get Started
                    </Link>
                </div>
            </div>

            <!-- Features Grid -->
            <div class="mx-auto mt-24 max-w-5xl">
                <div class="grid gap-8 md:grid-cols-3">
                    <!-- Feature 1 -->
                    <div class="rounded-xl border border-[#2a2a3a] bg-[#1a1a24] p-8 hover:border-[#00d4ff]/50 hover:shadow-[0_0_20px_rgba(0,212,255,0.1)] transition-all">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#00d4ff]/15">
                            <svg class="h-6 w-6 text-[#00d4ff]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-[#f5f5f7]">Import & Clean</h3>
                        <p class="mt-2 text-[#a8a8b8]">
                            Import audio samples from Google Sheets with reference transcriptions. Clean them using LLM or rule-based processing.
                        </p>
                    </div>

                    <!-- Feature 2 -->
                    <div class="rounded-xl border border-[#2a2a3a] bg-[#1a1a24] p-8 hover:border-[#00e5cc]/50 hover:shadow-[0_0_20px_rgba(0,229,204,0.1)] transition-all">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#00e5cc]/15">
                            <svg class="h-6 w-6 text-[#00e5cc]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                            </svg>
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-[#f5f5f7]">Validate & Compare</h3>
                        <p class="mt-2 text-[#a8a8b8]">
                            Review cleaned transcriptions with diff view, validate for quality, and compare original vs cleaned text side-by-side.
                        </p>
                    </div>

                    <!-- Feature 3 -->
                    <div class="rounded-xl border border-[#2a2a3a] bg-[#1a1a24] p-8 hover:border-[#ff006e]/50 hover:shadow-[0_0_20px_rgba(255,0,110,0.1)] transition-all">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#ff006e]/15">
                            <svg class="h-6 w-6 text-[#ff006e]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-[#f5f5f7]">Benchmark ASR</h3>
                        <p class="mt-2 text-[#a8a8b8]">
                            Transcribe audio with different ASR models, calculate WER/CER metrics, and compare performance across models.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Workflow Section -->
            <div class="mx-auto mt-24 max-w-4xl">
                <h2 class="text-center text-2xl font-bold text-[#f5f5f7]">How It Works</h2>
                <div class="mt-12 space-y-8">
                    <div class="flex gap-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#00d4ff] to-[#ff006e] text-white font-bold">1</div>
                        <div>
                            <h3 class="font-semibold text-[#f5f5f7]">Import Audio Samples</h3>
                            <p class="mt-1 text-[#a8a8b8]">Import from Google Sheets containing audio links and manual transcriptions. Audio files are stored with Spatie Media Library.</p>
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#00d4ff] to-[#ff006e] text-white font-bold">2</div>
                        <div>
                            <h3 class="font-semibold text-[#f5f5f7]">Clean Reference Transcripts</h3>
                            <p class="mt-1 text-[#a8a8b8]">Clean manual transcripts using LLM or rule-based processors. Review with diff view and validate for quality.</p>
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#00d4ff] to-[#ff006e] text-white font-bold">3</div>
                        <div>
                            <h3 class="font-semibold text-[#f5f5f7]">Transcribe with ASR Models</h3>
                            <p class="mt-1 text-[#a8a8b8]">Run audio through different ASR models (like Yiddish Libre). Each transcription is saved and rated against the reference.</p>
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#00d4ff] to-[#ff006e] text-white font-bold">4</div>
                        <div>
                            <h3 class="font-semibold text-[#f5f5f7]">Compare & Benchmark</h3>
                            <p class="mt-1 text-[#a8a8b8]">View WER/CER metrics per audio sample and per model. Find the best performing ASR model for Yiddish transcription.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="container mx-auto px-6 py-12 text-center text-[#6e6e7e]">
            <p>Built for Yiddish speech recognition research</p>
        </footer>
    </div>
</template>
