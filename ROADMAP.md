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
- [x] Add "Re-clean with different settings" option

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

### 7. Add Edit Capability

- [ ] Edit `reference_text_clean` inline
- [ ] `PATCH /audio-samples/{id}` endpoint
- [ ] Save updates status history

### 8. Create AudioSample Without Transcript ✅

- [x] `AudioSampleController@store` endpoint
  - `POST /audio-samples`
  - Accepts: name, audio (URL or file), transcript (URL or file - required)
- [x] Status `pending_transcript` defined in model

### 9. Upload/Add Reference Transcript to Existing Sample ✅

- [x] `AudioSampleController@uploadTranscript` endpoint
  - `POST /audio-samples/{id}/transcript`
- [x] Route defined in `web.php`
- [ ] UI: "Replace Transcript" option on detail page (resets cleaned text)

---

## Part 2: ASR Benchmarking (Future)

### Transcribe (Step 5)
- [ ] ASR service integration
- [ ] "Transcribe" button on validated AudioSamples
- [ ] Calculate WER/CER vs reference
- [ ] Save as `Transcription` model

### Import Transcriptions
- [ ] Import .txt with model name/version
- [ ] Bulk import

### Benchmark Views
- [ ] Model leaderboard
- [ ] Per-sample comparison
- [ ] Per-model results
- [ ] Charts & export

---

## File Reference

| Task | Files |
|------|-------|
| Import/Create | `AudioSampleController.php` (`create`, `store`, `importSheet`, `showRun`) |
| Clean | `AudioSampleController.php` (`clean`) |
| Detail page | `AudioSamples/Show.vue` |
| Import page | `AudioSamples/Create.vue` |
| Dashboard | `Dashboard.vue`, `DashboardController.php` |
| Sidebar | `AppSidebar.vue` |
| Routes | `routes/web.php` |

---

## Status Flow

```
pending_transcript → imported → cleaning → cleaned → validated
        ↓               ↑                     │
  (upload transcript)   └─── (re-clean) ──────┘
```
