# Yiddish ASR Benchmark - Roadmap

## Correct Flow (Per Spec)

```
1. IMPORT → AudioSample created (raw transcript, audio file)
2. CLEAN  → User triggers cleaning from AudioSample detail
3. REVIEW → Diff view, edit if needed
4. VALIDATE → Mark as "Benchmark Ready"
5. BENCHMARK → ASR transcription & comparison (Part 2)
```

---

## Part 1: Completed Infrastructure

- [x] `AudioSample` model with Spatie Media Library
- [x] `Transcription` model with WER/CER fields
- [x] LLM cleaning service (OpenRouter, Anthropic, etc.)
- [x] Rule-based cleaning with presets
- [x] Diff view (unified/split)
- [x] Google OAuth integration
- [x] Queue-based background processing

---

## Part 1.5: Architecture Fix (Current Sprint)

### ⚠️ CRITICAL: Separate Import from Cleaning

**Current (Wrong):** Import + Clean happen together in `ProcessAudioSampleJob`
**Correct:** Import creates raw AudioSample, cleaning is triggered separately

### 1. Refactor Import (Step 1) ✅

**Goal:** Import creates AudioSample with raw data only, NO cleaning.

- [x] Consolidate all import logic into `AudioSampleController`
  - `create()` - show import page
  - `store()` - manual single-sample creation (URL or file for audio/transcript)
  - `importSheet()` - batch import from Google Sheets
  - `showRun()` - display import run status
- [x] Remove `ProcessController` (no longer needed)
- [x] Update routes to use `audio-samples.*` naming:
  - `GET /import` → `audio-samples.create`
  - `POST /import/sheet` → `audio-samples.import-sheet`
  - `GET /import/runs/{run}` → `audio-samples.run`
- [x] Add new status values to `AudioSample`:
  - `pending_transcript` → has audio, no transcript
  - `imported` → has raw transcript, not cleaned
  - `cleaning` → cleaning in progress
  - `cleaned` → ready for review
  - `validated` → benchmark ready

### 2. Create Clean Action (Step 2) ✅

**Goal:** User triggers cleaning from AudioSample detail page.

- [x] `AudioSampleController@clean` endpoint
  - `POST /audio-samples/{id}/clean`
  - Accepts: preset, mode, llm_provider, llm_model
  - Runs cleaning synchronously (single doc)
- [x] Route: `Route::post('/audio-samples/{audioSample}/clean', ...)`

### 3. Update AudioSample Detail Page (Show.vue) ✅

- [x] Add cleaning form section (preset, mode, LLM options)
- [x] "Clean Transcript" button triggers cleaning
- [x] Show loading state during cleaning
- [x] After clean: show diff view (existing)
- [x] Add "Re-clean with different settings" option (collapsible form)

### 4. Update Import Page (Create.vue) ✅

- [x] Renamed from `Process.vue` to `AudioSamples/Create.vue`
- [x] Page title: "Import Audio Samples"
- [x] Routes changed from `/process` to `/import`
- [x] Only two import methods:
  - Google Sheets (batch import)
  - Manual Create (single sample with URL or file upload)
- [x] Removed Google Drive import option
- [x] Manual create supports URL or file for both audio and transcript

### 5. Update Dashboard ✅

- [x] Workflow progress cards: Imported → Cleaned → Validated → Benchmark Ready
- [x] Stats by status:
  - Total samples
  - Imported (needs cleaning)
  - Cleaned (needs review)
  - Validated (benchmark ready)

### 6. Update Sidebar & Terminology ✅

- [x] "Process" → "Import"
- [x] Updated sidebar href to `audio-samples.create`

### 7. Add Edit Capability ✅

- [x] Edit `reference_text_clean` inline
- [x] `PATCH /audio-samples/{id}` endpoint
- [x] Save updates status history

### 8. Create AudioSample Without Transcript ✅

- [x] `AudioSampleController@store` endpoint
  - `POST /audio-samples`
  - Accepts: name, audio (URL or file), transcript (URL or file - required)
- [x] Status `pending_transcript` defined in model

### 9. Upload/Add Reference Transcript to Existing Sample ✅

- [x] `AudioSampleController@uploadTranscript` endpoint
  - `POST /audio-samples/{id}/transcript`
- [x] Route defined in `web.php`
- [x] UI: "Replace Transcript" option on detail page (resets cleaned text)

### 10. UI/UX Improvements ✅

- [x] **Index.vue**: Update status column to match new workflow statuses
  - Show proper status badges (Needs Transcript, Needs Cleaning, Ready for Review, Benchmark Ready, Failed)
  - Remove old "processing" status references
- [x] **Index.vue**: Update "Preset" column to "Method"
  - Show preset name for rule-based cleaning (with chip icon)
  - Show model name for LLM cleaning (with sparkle icon)
- [x] **Index.vue**: Add row selection for bulk operations
  - Checkbox column with select all
  - Bulk action bar when items selected
  - Bulk clean action for samples in "imported" status
- [x] **Index.vue**: Improved pagination
  - Smart pagination with ellipsis for many pages
  - Category filter added
- [x] **Show.vue**: Improve cleaning form UX
  - Make re-clean more prominent for already-cleaned samples (collapsible card instead of hidden details)
  - Clearer visual hierarchy for workflow steps

---

## Part 2: ASR Benchmarking ✅

### ASR Service Layer ✅
- [x] `AsrDriverInterface` - Common interface for ASR providers
- [x] `AsrManager` - Factory for creating driver instances
- [x] `YiddishLabsDriver` - YiddishLabs API integration with async polling
- [x] `WhisperDriver` - OpenAI Whisper API integration
- [x] `AsrResult` - Data transfer object for transcription results
- [x] Configuration in `config/asr.php`

### WER/CER Calculation ✅
- [x] `WerCalculator` - Custom Levenshtein-based implementation
- [x] `WerResult` - Data object with WER, CER, and error breakdown
- [x] Calculates substitutions, insertions, deletions at word and character level

### Transcription Job ✅
- [x] `TranscribeAudioSampleJob` - Queue job for ASR transcription
- [x] Dispatches ASR request, calculates WER/CER, saves Transcription
- [x] Handles async polling for YiddishLabs provider

### Controllers ✅
- [x] `TranscriptionController` - Manual entry, import, delete, recalculate
- [x] `BenchmarkController` - Public leaderboard, model detail, comparison views
- [x] `AsrController` - API endpoint for listing ASR providers
- [x] `AudioSampleController` - Added `transcribe()` and `bulkTranscribe()` methods

### UI: AudioSample Detail (Show.vue) ✅
- [x] ASR transcription form (provider/model selection, notes)
- [x] Manual benchmark entry form (model name, version, hypothesis text, notes)
- [x] Transcriptions list with WER/CER display
- [x] Error breakdown (S/I/D counts)
- [x] Delete transcription button
- [x] Only visible for "Benchmark Ready" samples

### UI: Benchmark Views ✅
- [x] `Benchmark/Index.vue` - Public leaderboard with sortable columns
- [x] `Benchmark/Model.vue` - Per-model detail with error breakdown chart
- [x] `Benchmark/Compare.vue` - Multi-model comparison view
- [x] `AppSidebar.vue` - Added top-level "Benchmarks" navigation

### Routes ✅
- [x] `POST /audio-samples/{id}/transcribe` - Run ASR transcription
- [x] `POST /audio-samples/{id}/transcriptions` - Manual entry
- [x] `DELETE /audio-samples/{id}/transcriptions/{transcription}` - Delete
- [x] `POST /transcriptions/{id}/recalculate` - Recalculate WER/CER
- [x] `GET /benchmarks` - Public leaderboard
- [x] `GET /benchmarks/{model}` - Model detail
- [x] `GET /benchmarks/compare` - Comparison view
- [x] `GET /api/asr/providers` - List ASR providers

### Settings Integration ✅
- [x] `SettingsController` - Added yiddishlabs to ASR providers list
- [x] `Credentials.vue` - Added YiddishLabs to provider labels

---

## File Reference

| Task | Files |
|------|-------|
| Import/Create | `AudioSampleController.php` (`create`, `store`, `importSheet`, `showRun`) |
| Clean | `AudioSampleController.php` (`clean`) |
| Transcribe | `AudioSampleController.php` (`transcribe`, `bulkTranscribe`) |
| ASR Service | `app/Services/Asr/*` |
| ASR Job | `TranscribeAudioSampleJob.php` |
| Transcription CRUD | `TranscriptionController.php` |
| Benchmarks | `BenchmarkController.php`, `Benchmark/*.vue` |
| Detail page | `AudioSamples/Show.vue` |
| Import page | `AudioSamples/Create.vue` |
| Dashboard | `Dashboard.vue`, `DashboardController.php` |
| Sidebar | `AppSidebar.vue` |
| Routes | `routes/web.php` |
| ASR Config | `config/asr.php` |

---

## Status Flow

```
pending_transcript → imported → cleaning → cleaned → validated
        ↓               ↑                     │          │
  (upload transcript)   └─── (re-clean) ──────┘          │
                                                         ↓
                                                  [ASR Transcription]
                                                         │
                                                         ↓
                                                   Transcription
                                                  (WER/CER stored)
```
