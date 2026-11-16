# Transcription engine evaluation (recommendation only; nothing has been changed)

Current: local `faster-whisper`, model `base`, CPU, `int8`, `vad_filter=True`, no vocabulary hint (`scripts/transcribe_whisper.py`). Output is WebVTT (captions at `/vtt/{id}`) plus plain text for the pipeline.

## Options

| | Medical terms / drugs / genes | Timestamps and VTT | CPU / RAM | Speed | Cost |
|---|---|---|---|---|---|
| `base` (now) | Weakest. Expect misspellings of drug, supplement and gene names, dropped acronyms | Yes (segments) | Lowest | Fastest | $0 |
| `small` | Noticeably better, still stumbles on rare terms | Yes | Low | Fast | $0 |
| `medium` | Good general accuracy; the usual sweet spot on CPU | Yes | Moderate | Slow-ish | $0 |
| `large-v3` (or distilled large) | Best local accuracy, best on jargon | Yes | High | Slowest on CPU | $0 |
| OpenAI `gpt-transcribe` (API) | Strong, accepts a `prompt` and a `keywords` list (literal terms) | **Not documented**: the docs show JSON with `text` and `languages` only; segment/word timestamps and VTT/SRT are documented only for `whisper-1`, which OpenAI is retiring (26 Feb 2027) | None on the server | Fast (network) | $0.0045 per audio minute (about $0.27 per hour); 25 MB per file |

Resource and speed figures for the local sizes are rough rules of thumb (int8 on CPU: RAM roughly 0.5 GB base, 1 GB small, 2-2.5 GB medium, 4 GB+ large; speed roughly several times real time for base/small, around real time or slower for medium/large). They depend on the server. The harness below measures them on your hardware instead of guessing; run it on the real server (or one with the same CPU/RAM).

Facts about `gpt-transcribe` come from OpenAI's speech-to-text guide and model page (11 Nov 2026). The absence of timestamps is "not documented", not "confirmed impossible"; the test plan checks it.

## Recommendation

1. **Keep local faster-whisper** as the engine. The site needs timestamped VTT captions, which `gpt-transcribe` does not document, and the local engine costs nothing per video.
2. **Test `small` and `medium` (and `large-v3` if the server has about 4 GB free RAM and you accept slow runs) against `base`.** Expected outcome: move from `base` to `small` or `medium`, whichever the server can run without hurting the site; transcription already runs as a background job, so slower is acceptable if it is reliable.
3. **Add vocabulary biasing** (cheap, no new model): pass the medical glossary as `initial_prompt` (and `hotwords` where the installed faster-whisper supports it), built from `content-pipeline/standards/*.json` and the entity library. The harness has a `--bias` switch to measure the gain before changing the script.
4. Keep Pass 0's glossary corrections (with its new number guard) as the second line of defence; they fix wording but cannot recover audio the engine misheard.
5. Treat `gpt-transcribe` as an optional second opinion for hard clips (text only), not a replacement, unless the test shows timestamps work and are accurate.

## Comparison test plan

Needs your approval for the paid step; the local steps cost nothing.

1. Choose 5-6 clips of 8-10 minutes: varied speakers/audio quality, at least two dense with drug, supplement, gene and lab terms, one with numbers/doses.
2. Make a human-corrected reference `.txt` per clip (correct an existing Whisper transcript; about 30-40 minutes per 10-minute clip). Store it outside the repo if the content is private.
3. Build the glossary: `--terms content-pipeline/standards/medical-terminology-standards.json` (or a text file of terms from the entity library).
4. Local run (no cost), on the real server:
   ```bash
   python3 scripts/benchmark_transcription.py --audio clip1.wav --reference clip1.txt \
     --audio clip2.wav --reference clip2.txt --models base,small,medium,large-v3 \
     --terms content-pipeline/standards/medical-terminology-standards.json --bias \
     --out scripts/output/benchmark
   ```
   It reports per clip and model: word error rate, glossary-term recall, number/dose accuracy, real-time factor, peak RAM, and whether timestamps exist, and writes the `.vtt` for each run so captions can be spot-checked.
5. Paid check (only after you approve; about 10 clips-minutes costs about $0.05): add `--gpt-transcribe --allow-paid-api` with `OPENAI_API_KEY` set. The script prints the estimate first and refuses without the flag.
6. Decision rule (suggested): pick the smallest local model whose term recall and number accuracy are within ~2 points of the best local model, whose real-time factor and RAM fit the server alongside the site, and whose VTT timestamps look right in the player. Adopt `gpt-transcribe` only if it beats that on terms by a wide margin *and* a timestamp strategy exists.
7. Then change `whisper_model` in the admin (no code change needed for the model size) and re-test one video through the single-video procedure in `AI_PIPELINE_MODERNIZATION.md`.

The scorer itself is tested offline: `python3 -I scripts/benchmark_transcription.py --self-test`.
