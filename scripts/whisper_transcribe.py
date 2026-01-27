#!/usr/bin/env python3
"""
Local Whisper transcription script for yiddish-cleaner.
Outputs JSON with word-level timestamps.
"""

import argparse
import json
import sys
import warnings

# Suppress warnings for cleaner output
warnings.filterwarnings("ignore")

def main():
    parser = argparse.ArgumentParser(description='Transcribe audio using local Whisper')
    parser.add_argument('--audio', required=True, help='Path to audio file')
    parser.add_argument('--model', default='base', help='Whisper model size')
    parser.add_argument('--language', default='yi', help='Language code (yi for Yiddish)')
    parser.add_argument('--device', default='cpu', help='Device to use (cpu or cuda)')
    parser.add_argument('--output', default='json', help='Output format')
    
    args = parser.parse_args()
    
    try:
        import whisper
    except ImportError:
        print(json.dumps({"error": "openai-whisper not installed. Run: pip install openai-whisper"}))
        sys.exit(1)
    
    try:
        # Load the model
        model = whisper.load_model(args.model, device=args.device)
        
        # Transcribe with word timestamps
        result = model.transcribe(
            args.audio,
            language=args.language,
            word_timestamps=True,
            verbose=False
        )
        
        # Extract word-level data
        words = []
        for segment in result.get('segments', []):
            for word_info in segment.get('words', []):
                words.append({
                    'word': word_info.get('word', ''),
                    'start': word_info.get('start', 0),
                    'end': word_info.get('end', 0),
                    'probability': word_info.get('probability', None)
                })
        
        # Build output
        output = {
            'text': result.get('text', ''),
            'language': result.get('language', args.language),
            'duration': result.get('duration', None),
            'segments': result.get('segments', []),
            'words': words
        }
        
        print(json.dumps(output))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == '__main__':
    main()
