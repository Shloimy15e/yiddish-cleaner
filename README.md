# Yiddish ASR Benchmark

A web application for benchmarking Yiddish Automatic Speech Recognition (ASR) models. Import audio samples, manage reference transcriptions, clean and validate them, then compare ASR model performance using WER/CER metrics.

## Features

### Core Workflow

- **Import Audio Samples** - Import from Google Sheets with audio links or upload files directly
- **Manage Transcriptions** - Transcriptions are managed separately and can be linked to audio samples
- **Clean Reference Transcripts** - LLM-based or rule-based cleaning with diff view
- **Validate Transcripts** - Review, compare, and mark transcripts as validated
- **ASR Benchmarking** - Transcribe audio with different ASR models and calculate WER/CER
- **Model Comparison** - Compare performance across models on public benchmark page

### Key Concepts

**Two Types of Transcriptions:**
1. **Base Transcriptions** (Reference/Ground Truth) - The human-verified "correct" transcription used as reference
2. **ASR Transcriptions** (Hypothesis) - Machine-generated transcriptions to benchmark against the reference

**Transcription Workflow:**
- Base transcriptions can exist as "orphan" (not linked to any audio) or linked to an audio sample
- ASR transcriptions always require an audio sample context
- Audio samples progress through statuses: `draft` → `pending_base` → `unclean` → `ready` → `benchmarked`

## Tech Stack

- **Backend**: Laravel 12, PHP 8.2+
- **Frontend**: Vue 3, Inertia.js, TypeScript, Tailwind CSS
- **Database**: SQLite/MySQL/PostgreSQL
- **File Storage**: Spatie Media Library
- **Queue**: Laravel Queue for background processing

## Installation

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- npm

### Setup

1. Clone the repository:
```bash
git clone https://github.com/Shloimy15e/yiddish-cleaner.git
cd yiddish-cleaner
```

2. Install dependencies:
```bash
composer install
npm install
```

3. Copy environment file and generate key:
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure your database in `.env`:
```env
DB_CONNECTION=sqlite
# or configure MySQL/PostgreSQL
```

5. Run migrations:
```bash
php artisan migrate
```

6. Build frontend assets:
```bash
npm run build
```

7. Start the development server:
```bash
composer run dev
```

This starts the Laravel server, queue worker, and Vite dev server concurrently.

## Configuration

### Google Integration

To import from Google Sheets, configure OAuth credentials in `.env`:

```env
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://localhost:8000/settings/google/callback
```

### LLM Providers

Configure API keys for LLM-based cleaning in Settings > Credentials:

- OpenRouter
- Anthropic
- OpenAI
- Google AI
- Groq

### Feature Flags

Enable optional features in `.env`:

```env
FEATURE_TRAINING=false  # Enable training data export features
```

## Usage

### 1. Import Audio Samples

**From Google Sheets:**
1. Go to **Import** page
2. Enter your Google Sheet URL containing:
   - `Name` column - Sample identifier
   - `Doc Link` column - Link to transcript document (optional)
   - `Audio Link` column - Link to audio file
3. Select cleaning preset and mode (LLM or rule-based)
4. Click **Process Sheet**

**Single Upload:**
1. Go to **Import** page, **Single Import** tab
2. Upload audio file and/or transcript file
3. Optionally link an existing orphan transcription

### 2. Manage Transcriptions

1. Go to **Transcriptions** page
2. Create new base transcriptions or view existing ones
3. From transcription detail page:
   - Clean the text using presets or LLM
   - Review raw vs cleaned text
   - Validate when ready
   - Link/unlink to audio samples

### 3. Prepare Audio Samples

1. Go to **Audio Samples** page
2. Audio samples show their status:
   - **Pending Base** - Needs a base transcription linked
   - **Unclean** - Has transcription but not validated
   - **Ready** - Validated and ready for ASR benchmarking
   - **Benchmarked** - Has ASR transcriptions
3. Link transcriptions to audio samples as needed

### 4. Benchmark ASR Models

1. Open a **Ready** audio sample
2. In the Benchmark section, select an ASR provider/model
3. Run transcription
4. The system calculates:
   - **WER** (Word Error Rate)
   - **CER** (Character Error Rate)
   - Substitutions, insertions, deletions
5. Compare results across different models

### 5. View Public Benchmarks

- Visit `/benchmark` to see public benchmark results
- Compare average WER/CER across ASR models
- View per-model details and statistics

## Data Models

### AudioSample

Represents an audio clip:

- `name` - Sample identifier
- `audio_duration_seconds` - Audio length
- `status` - Workflow status (draft, pending_base, unclean, ready, benchmarked)

Relationships:
- `baseTranscription` - The linked reference transcription (HasOne)
- `asrTranscriptions` - ASR outputs for benchmarking (HasMany)

Media collections:
- `audio` - The audio file (mp3, wav, ogg, m4a, flac)

### Transcription

Can be either a base (reference) or ASR (hypothesis) transcription:

**Common fields:**
- `type` - 'base' or 'asr'
- `audio_sample_id` - Link to audio (required for ASR, optional for base)
- `status` - pending, processing, completed, failed
- `source` - imported, generated, manual

**Base transcription fields:**
- `name` - Transcription name
- `text_raw` - Original imported text
- `text_clean` - Cleaned/normalized text
- `clean_rate` - Quality score (0-100)
- `validated_at` - Validation timestamp
- `cleaning_preset` - Which preset was used

**ASR transcription fields:**
- `model_name` - ASR model identifier
- `model_version` - Model version
- `hypothesis_text` - The ASR output
- `wer`, `cer` - Error rates
- `substitutions`, `insertions`, `deletions` - Error breakdown

Media collections:
- `source_file` - Original transcript file (for base)
- `cleaned_file` - Cleaned transcript file (for base)
- `hypothesis_transcript` - ASR output file (for ASR)

### ProcessingRun

Tracks batch import operations:

- `batch_id` - Unique batch identifier
- `preset` - Cleaning preset used
- `mode` - 'llm' or 'rule_based'
- `status` - pending, processing, completed, failed
- `stats` - Processing statistics

## Cleaning Presets

| Preset | Description | Use Case |
|--------|-------------|----------|
| `titles_only` | Removes titles/headings, keeps brackets | Older texts (5710-5711) |
| `full_clean` | Removes titles AND inline brackets | Standard texts (5712+) |
| `with_editorial` | Standard + editorial Hebrew citations | Texts with Hebrew sources |
| `heavy` | All processors including parentheses | Heavily formatted texts |
| `minimal` | Only whitespace and special chars | Light cleanup needed |

## Development

### Commands

```bash
# Development server (Laravel + Vite + Queue)
composer run dev

# Run tests
composer run test

# Lint PHP code
composer run lint

# Build for production
npm run build

# Type check
npm run build
```

### Project Structure

```
app/
├── Http/Controllers/
│   ├── AudioSampleController.php  # Audio sample management
│   ├── TranscriptionController.php # Transcription CRUD & cleaning
│   ├── ImportController.php       # Import from sheets/files
│   ├── BenchmarkController.php    # Public benchmark pages
│   └── DashboardController.php    # Dashboard stats
├── Jobs/
│   ├── CleanTranscriptionJob.php  # Background cleaning
│   ├── ProcessAudioSampleJob.php  # Audio processing
│   └── ProcessSheetBatchJob.php   # Batch import
├── Models/
│   ├── AudioSample.php            # Audio + status workflow
│   ├── Transcription.php          # Base & ASR transcriptions
│   └── ProcessingRun.php          # Batch import tracking
└── Services/
    ├── Cleaning/                  # Text cleaning processors
    ├── Google/                    # Sheets/Drive integration
    └── Llm/                       # LLM provider drivers

resources/js/pages/
├── AudioSamples/                  # Sample list and detail
├── Transcriptions/                # Transcription management
├── Import/                        # Import interface
├── Benchmark/                     # Public benchmarks
├── Dashboard.vue                  # Main dashboard
└── Welcome.vue                    # Landing page
```

## API Routes

### Import
- `GET /imports` - List import runs
- `POST /imports` - Start new import
- `GET /imports/{run}` - View import run details

### Audio Samples
- `GET /audio-samples` - List audio samples
- `GET /audio-samples/{id}` - View sample details
- `POST /audio-samples/{id}/transcribe` - Run ASR transcription

### Transcriptions
- `GET /transcriptions` - List base transcriptions
- `POST /transcriptions` - Create base transcription
- `POST /transcriptions/{id}/clean` - Clean transcription
- `POST /transcriptions/{id}/validate` - Mark as validated
- `POST /transcriptions/{id}/link` - Link to audio sample

### Benchmarks (Public)
- `GET /benchmark` - Public benchmark overview
- `GET /benchmark/compare` - Model comparison
- `GET /benchmark/models/{name}` - Model details

## License

MIT

