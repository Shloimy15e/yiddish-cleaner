# Yiddish ASR Benchmark

A web application for benchmarking Yiddish Automatic Speech Recognition (ASR) models. Import audio samples with reference transcriptions, clean and validate them, then compare ASR model performance using WER/CER metrics.

## Features

- **Import Audio Samples** - Import from Google Sheets with audio links and manual transcriptions
- **Clean Reference Transcripts** - LLM-based or rule-based cleaning with diff view
- **Validate Transcripts** - Review, compare, and mark transcripts as validated
- **ASR Benchmarking** - Transcribe audio with different ASR models and calculate WER/CER
- **Model Comparison** - Compare performance across models to find the best performer

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

1. Go to **Process** page
2. Enter your Google Sheet URL containing:
   - `Name` column - Sample identifier
   - `Doc Link` column - Link to transcript document
   - `Audio Link` column - Link to audio file
3. Select cleaning preset and mode (LLM or rule-based)
4. Click **Process Sheet**

### 2. Review & Validate

1. Go to **Audio Samples** page
2. Click on a sample to view details
3. Use the tabs to switch between:
   - **Cleaned Text** - The processed reference transcript
   - **Original Text** - The raw imported text
   - **Side by Side** - Compare both versions
   - **Diff View** - See line-by-line changes
4. Click **Validate** when the transcript is correct

### 3. Benchmark ASR Models

1. Open a validated audio sample
2. Run transcription with an ASR model (e.g., Yiddish Libre)
3. The system calculates:
   - **WER** (Word Error Rate)
   - **CER** (Character Error Rate)
   - Substitutions, insertions, deletions
4. Compare results across different models

### 4. View Benchmarks

- View per-sample transcription results
- Compare average WER/CER across ASR models
- Export results for further analysis

## Data Models

### AudioSample

Represents an audio clip with its reference transcript:

- `name` - Sample identifier
- `reference_text_raw` - Original imported transcript
- `reference_text_clean` - Cleaned/normalized transcript
- `audio_duration_seconds` - Audio length
- `clean_rate` - Quality score (0-100)
- `validated_at` - Validation timestamp

Media collections:
- `audio` - The audio file
- `reference_transcript` - Text file of the transcript

### Transcription

An ASR output for an audio sample:

- `audio_sample_id` - Reference to the audio sample
- `model_name` - ASR model identifier (e.g., "yiddish-libre")
- `model_version` - Model version
- `source` - "generated" or "imported"
- `hypothesis_text` - The ASR output
- `wer`, `cer` - Error rates
- `substitutions`, `insertions`, `deletions` - Error breakdown

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
│   ├── AudioSampleController.php  # Audio sample CRUD
│   ├── ProcessController.php      # Import & processing
│   └── DashboardController.php    # Dashboard stats
├── Jobs/
│   ├── ProcessAudioSampleJob.php  # Background processing
│   └── ProcessSheetBatchJob.php   # Batch import
├── Models/
│   ├── AudioSample.php            # Audio + reference transcript
│   ├── Transcription.php          # ASR output + metrics
│   └── ProcessingRun.php          # Batch import tracking
└── Services/
    ├── Cleaning/                  # Text cleaning processors
    ├── Google/                    # Sheets/Drive integration
    └── Llm/                       # LLM provider drivers

resources/js/pages/
├── AudioSamples/                  # Sample list and detail views
├── Dashboard.vue                  # Main dashboard
├── Process.vue                    # Import interface
└── Welcome.vue                    # Landing page
```

## License

MIT
