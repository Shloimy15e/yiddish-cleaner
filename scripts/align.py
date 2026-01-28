#!/usr/bin/env python3
"""
Forced alignment script using WhisperX.
Called from PHP to generate word-level timing data.

Usage:
    python align.py <audio_path> <text_file_path> [--language yi] [--model large-v2]

Output:
    JSON with word-level timing data to stdout
"""

import argparse
import json
import sys
import os

def main():
    parser = argparse.ArgumentParser(description='Forced alignment using WhisperX')
    parser.add_argument('audio_path', help='Path to audio file')
    parser.add_argument('text_path', help='Path to text file with transcript')
    parser.add_argument('--language', '-l', default='yi', help='Language code (default: yi for Yiddish)')
    parser.add_argument('--model', '-m', default='large-v2', help='Whisper model size (default: large-v2)')
    parser.add_argument('--device', '-d', default='auto', help='Device: auto, cpu, or cuda')
    parser.add_argument('--compute-type', '-c', default='float16', help='Compute type (default: float16)')
    
    args = parser.parse_args()
    
    # Validate inputs
    if not os.path.exists(args.audio_path):
        print(json.dumps({'error': f'Audio file not found: {args.audio_path}'}), file=sys.stderr)
        sys.exit(1)
    
    if not os.path.exists(args.text_path):
        print(json.dumps({'error': f'Text file not found: {args.text_path}'}), file=sys.stderr)
        sys.exit(1)
    
    # Read transcript
    with open(args.text_path, 'r', encoding='utf-8') as f:
        transcript = f.read().strip()
    
    if not transcript:
        print(json.dumps({'error': 'Transcript is empty'}), file=sys.stderr)
        sys.exit(1)
    
    try:
        import whisperx
        import torch
    except ImportError as e:
        print(json.dumps({
            'error': f'Missing dependency: {e}. Install with: pip install whisperx torch'
        }), file=sys.stderr)
        sys.exit(1)
    
    # Determine device
    if args.device == 'auto':
        device = 'cuda' if torch.cuda.is_available() else 'cpu'
    else:
        device = args.device
    
    # Adjust compute type for CPU
    compute_type = args.compute_type
    if device == 'cpu' and compute_type == 'float16':
        compute_type = 'int8'  # float16 not supported on CPU
    
    try:
        # Load alignment model
        # WhisperX uses wav2vec2 models for alignment
        align_model, align_metadata = whisperx.load_align_model(
            language_code=args.language,
            device=device
        )
        
        # Load audio
        audio = whisperx.load_audio(args.audio_path)
        
        # Create segments from transcript
        # WhisperX expects segments with text - we create one big segment
        # and let it align word by word
        segments = [{
            'text': transcript,
            'start': 0.0,
            'end': len(audio) / 16000  # Duration in seconds (16kHz sample rate)
        }]
        
        # Perform alignment
        result = whisperx.align(
            segments,
            align_model,
            align_metadata,
            audio,
            device,
            return_char_alignments=False
        )
        
        # Extract word-level data
        words = []
        for segment in result.get('segments', []):
            for word_data in segment.get('words', []):
                word = {
                    'word': word_data.get('word', ''),
                    'start': round(word_data.get('start', 0), 3),
                    'end': round(word_data.get('end', 0), 3),
                }
                # Add confidence/score if available
                if 'score' in word_data:
                    word['confidence'] = round(word_data['score'], 3)
                words.append(word)
        
        # Output result
        output = {
            'success': True,
            'language': args.language,
            'duration': round(len(audio) / 16000, 3),
            'word_count': len(words),
            'words': words
        }
        
        print(json.dumps(output, ensure_ascii=False))
        
    except Exception as e:
        print(json.dumps({
            'error': str(e),
            'type': type(e).__name__
        }), file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
