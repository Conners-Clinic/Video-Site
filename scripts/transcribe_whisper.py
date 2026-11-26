#!/usr/bin/env python3
"""
Local transcription via faster-whisper. Outputs WebVTT next to the input basename.
Exit 0 on success, 1 on failure (message on stderr).
"""
import argparse
import os
import sys


def format_timestamp(seconds):
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = seconds % 60
    return f"{hours:02d}:{minutes:02d}:{secs:06.3f}".replace(".", ",")


def write_vtt(segments, output_path):
    with open(output_path, "w", encoding="utf-8") as f:
        f.write("WEBVTT\n\n")
        for segment in segments:
            start = format_timestamp(segment.start)
            end = format_timestamp(segment.end)
            text = (segment.text or "").strip()
            if not text:
                continue
            f.write(f"{start} --> {end}\n{text}\n\n")


def main():
    parser = argparse.ArgumentParser(description="Transcribe audio to WebVTT using faster-whisper")
    parser.add_argument("--input", required=True, help="Path to WAV/MP3 audio file")
    parser.add_argument("--output_dir", required=True, help="Directory for output VTT")
    parser.add_argument("--model", default="base", help="Whisper model size (tiny, base, small, ...)")
    parser.add_argument("--language", default="en", help="Language code or 'auto'")
    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(f"Input file not found: {args.input}", file=sys.stderr)
        return 1

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        print(
            "faster-whisper is not installed. Run: pip install faster-whisper",
            file=sys.stderr,
        )
        return 1

    os.makedirs(args.output_dir, exist_ok=True)
    base = os.path.splitext(os.path.basename(args.input))[0]
    vtt_path = os.path.join(args.output_dir, base + ".vtt")

    try:
        model = WhisperModel(args.model, device="cpu", compute_type="int8")
        lang = None if args.language == "auto" else args.language
        segments_iter, _info = model.transcribe(args.input, language=lang, vad_filter=True)
        segments = list(segments_iter)
        write_vtt(segments, vtt_path)
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1

    if not os.path.isfile(vtt_path):
        print("VTT file was not created", file=sys.stderr)
        return 1

    print(vtt_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
