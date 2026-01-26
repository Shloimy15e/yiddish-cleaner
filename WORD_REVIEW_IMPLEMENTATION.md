# Word-Level ASR Transcription Review System - Implementation Progress

## Overview
Interactive word-level transcription review with time-coded audio playback, confidence-based filtering, inline corrections (edit, add, delete), and correction metrics alongside WER.

---

## Stage 1: Backend Foundation
### 1.1 Extend ASR Result Structure
- [ ] Modify `AsrResult.php` to add `?array $words` property
- [ ] Create `AsrWord` value object with `word`, `start`, `end`, `confidence`

### 1.2 Update ASR Drivers
- [ ] Update `WhisperDriver.php` to use `timestamp_granularities: ['word']`
- [ ] Update `YiddishLabsDriver.php` to enable `timestamps=true` and parse inline timestamps

### 1.3 Database Schema
- [ ] Create migration for `transcription_words` table
- [ ] Add `flagged_for_training` to `transcriptions` table
- [ ] Create `TranscriptionWord` model with relationships

### 1.4 Configuration
- [ ] Add `review.playback_padding_seconds` to `config/asr.php`

---

## Stage 2: Word Storage & Processing
### 2.1 Store Words on ASR Completion
- [ ] Update `TranscribeAudioSampleJob.php` to persist words after transcription

### 2.2 Transcription Model Methods
- [ ] Add `words()` relationship to Transcription model
- [ ] Add `getCorrectedText()` method
- [ ] Add `correctionCount()` method
- [ ] Add `hasWordData()` helper

---

## Stage 3: API Endpoints
### 3.1 Word Controller
- [ ] Create `TranscriptionWordController`
- [ ] Implement `index` action (get words + stats)
- [ ] Implement `update` action (edit/delete word)
- [ ] Implement `store` action (insert new word)

### 3.2 Routes
- [ ] Add API routes for word operations
- [ ] Add web routes if needed

---

## Stage 4: Frontend Components
### 4.1 Extend Audio Player
- [ ] Add `playRange(startTime, endTime)` method to `AudioPlayer.vue`
- [ ] Handle padding from config
- [ ] Auto-pause after range completes

### 4.2 Word Review Component
- [ ] Create `TranscriptionWordReview.vue`
- [ ] Word display with RTL support
- [ ] Confidence-based coloring
- [ ] Confidence threshold filter dropdown
- [ ] Click word to play audio snippet
- [ ] Edit word popover
- [ ] Delete word (strikethrough)
- [ ] Add word between words
- [ ] Visual display of corrections

### 4.3 TypeScript Types
- [ ] Add `TranscriptionWord` type
- [ ] Add `WordReviewStats` type

---

## Stage 5: Page Integration
### 5.1 AudioSamples Show Page
- [ ] Add "Review ASR" section/tab
- [ ] Integrate word review component
- [ ] Display metrics (WER + Corrections count)
- [ ] Add "Flag for Training" toggle

### 5.2 Polish & UX
- [ ] Loading states
- [ ] Error handling
- [ ] Keyboard navigation
- [ ] Accessibility

---

## Current Progress

### ✅ Completed
- **1.1** Extended `AsrResult.php` with `?array $words` property
- **1.2** Created `AsrWord.php` value object
- **1.2** Updated `WhisperDriver.php` to use `timestamp_granularities: ['word']`
- **1.2** Updated `YiddishLabsDriver.php` to enable `timestamps=true` and parse inline timestamps
- **1.3** Created migration for `transcription_words` table ✅ Migrated
- **1.3** Added `flagged_for_training` to `transcriptions` table
- **1.3** Created `TranscriptionWord` model with relationships
- **1.4** Added `review.playback_padding_seconds` to `config/asr.php`
- **2.1** Updated `TranscribeAudioSampleJob.php` to persist words after transcription
- **2.2** Added `words()` relationship to Transcription model
- **2.2** Added `getCorrectedText()`, `getCorrectionCount()`, `getCorrectionRate()` methods
- **2.2** Added `hasWordData()` and `storeWords()` helpers
- **3.1** Created `TranscriptionWordController` with index, store, update, destroy actions
- **3.1** Added `storeAtStart` for inserting at beginning
- **3.1** Added `toggleTrainingFlag` and `getCorrectedText` endpoints
- **3.2** Added API routes for word operations

### 🔄 In Progress
_(Paused for review)_

### ⏳ Pending
- Stage 4: Frontend Components
- Stage 5: Page Integration

---

## Notes
- YiddishLabs API returns inline timestamps in text when `timestamps=true` — format TBD, need sample response
- Whisper confidence derived from `avg_logprob` on segments, word-level may need approximation
- Inserted words take timestamp sliver from adjacent words
