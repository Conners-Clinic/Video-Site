# AI pipeline modernization (models, complete-transcript summaries, reliability)

Status: implemented locally, **not deployed, nothing activated**. Transcription (`transcript_system`) and all crons stay as they were (off).

## 1. Models (one place: `content-pipeline/config/pipeline-models.json`)

| Stage | Default | Reasoning | Notes |
|---|---|---|---|
| `summary` (summary + takeaways) | `gpt-6-luna` | low | whole transcript, 1 call |
| `summary_sections` (only for huge transcripts) | `gpt-6-luna` | low | per-section digests |
| `pass_0` transcript cleanup | `gpt-6-luna` | none | chunked, verified |
| `entity_extraction`, `entity_comparison` | `gpt-6-luna` | low | |
| `entity_review_queue`, `json_repair` | `gpt-6-luna` | none | |
| `pass_1` SEO blueprint, `pass_3` audit | `gpt-6-luna` | low | |
| `pass_2` article draft, `pass_4` final revision | `gpt-6.1-terra` | low | |

Per-stage override: **Admin → SEO Articles → Settings → Models per pass** (and the summary models on **Transcribe Videos**). The raw JSON (prices, reasoning, token limits, thresholds) is editable under **SEO Articles → Prompts & standards → Model config**; saving is rejected if the file loses its `defaults`/`models`/`stage_settings` sections. No model name appears in PHP, ajax or admin markup (a test enforces this).

Verified against OpenAI's model pages (11 Nov 2026): both IDs exist; 1.05M context, 128K max output; Chat Completions and Responses supported; `reasoning` effort `none|low|medium|high|xhigh|max` (default medium); list price per 1M tokens Luna $0.10 in / $0.01 cached / $0.50 out, Terra $1 / $0.05 / $5; inputs over 272K tokens bill 2x input and 1.5x output. **Not stated on those pages:** whether `temperature` is accepted, `max_tokens` vs `max_completion_tokens`, JSON-mode details, reasoning-token reporting. The client therefore sends `max_completion_tokens` + `reasoning_effort`, omits `temperature` for these models, and, if the API rejects any optional parameter, strips/swaps it once and retries (rejected requests are not billed). **Not yet confirmed against the live API** (no paid calls were made).

## 2. Complete-transcript summaries

`PT_AiSummarizeTranscript()` (`functions_ai_summary.php`), used by the post-transcription step, the batch "Generate SEO summaries" and the new single-video regenerate.

- Up to 150,000 estimated tokens (about 9 hours of speech, far beyond normal) the **entire** transcript goes to the model in one call; the old 6,000-character cut-off is gone. The prompt demands beginning/middle/end coverage, no invented claims, figures verbatim.
- Longer: the transcript is split on sentence boundaries into ~40,000-token sections with ~1,000 tokens of whole-sentence overlap; each section is digested to JSON (summary, key points, exact specifics); digests are merged hierarchically only if they exceed 60,000 tokens; one final call writes summary + takeaways from the digests of **every** section. Calls = sections + 1 (+ merges if needed).
- A section that fails (bad JSON twice, truncation, budget) fails the whole run with a message in `video_transcripts.error_message`. Nothing partial is saved, and the existing summary survives.
- Token counting uses a conservative character-based estimate (no tokenizer on shared hosting); it errs high, so chunks are never over the limit.

## 3. Completeness and fidelity

- Every OpenAI response is checked: `finish_reason=length` is a failure (previously Pass 0's 16,000-token cap could silently save a cut-off "cleaned" transcript, and JSON "repair" could invent the missing end). Truncated JSON is never sent to repair.
- Pass 0 now cleans the **whole** transcript in ~5,000-token chunks (no overlap, so nothing duplicates). Each chunk must keep 60-115% of its words and **every number** (doses, lab values, percentages, years) exactly; one retry naming the problem; otherwise the raw chunk is kept and flagged in `transcript_quality` (`fallback_chunks`, `numeric_flags`, `warnings`).
- `video_transcripts.plain_text` (verbatim), `video_seo_articles.raw_transcript`, the VTT file (captions), `cleaned_transcript` and the AI summary stay separate fields; nothing writes back to the first three.
- Bug fixed: `PT_InitializeSeoArticleFromTranscript()` read the transcript array with `->` so enqueueing always reported "no completed transcript"; `PT_GetDisplayTranscriptForVideo()` had the same problem.

## 4. Reliability and cost controls

- One client (`PT_AiChat`) for everything: retries (2) only for network/429/5xx; auth/quota errors stop at once; every call is logged with model, tokens (incl. reasoning and cached), estimated cost and finish reason.
- Per-job budget: default max $2.00 and 80 calls (`ai_max_cost_usd_per_job`, `ai_max_calls_per_job` in config), summary transcript cap 600,000 tokens (`ai_summary_max_transcript_tokens`). Exceeding one fails the step visibly.
- Cost report: `seo_pipeline_get_article` now returns `usage` (calls, tokens, estimated USD, per stage); the single-video regenerate shows calls and cost.
- Duplicates: queue rows are claimed atomically (SEO and transcription cron); a per-video DB lock stops cron and an admin click running the same step; enqueue refuses `complete` articles unless forced, resumes `failed` ones at the failed pass, and removes a racing duplicate row; stale `*_running` statuses and crashed queue rows recover.
- Descriptions: not rewritten automatically after transcription (opt-in setting); batch/single regenerate never touch descriptions; the original is backed up on first apply.
- Regenerate one summary without Whisper: **Transcribe Videos → Regenerate ONE video's summary** (refuses to re-bill an existing summary unless "replace existing" is ticked).

## 5. Estimated cost per ~1-hour video (about 13K transcript tokens)

Estimates from prompt sizes in the repo and assumed output lengths; not measured. Old = `gpt-4o-mini` + `gpt-4o`, list prices $0.15/$0.60 and $2.50/$10.

| | Old | New |
|---|---|---|
| Summary + takeaways | ~$0.0005 (first 6,000 chars only) | ~$0.002 (whole video) |
| Pass 0-1, entities, audit (Luna) | ~$0.02 | ~$0.02 (Pass 0 re-sends standards per chunk; still cheap) |
| Pass 2 + Pass 4 (Terra vs 4o) | ~$0.185 | ~$0.10 |
| **Full pipeline** | **~$0.21** | **~$0.12 (about 40% lower)** |

Reasoning tokens (billed as output) are the main uncertainty; stages use `none`/`low`. Re-check `seo_pipeline_logs.cost_usd` after the first real video. A 500,000-token transcript costs roughly $0.07 to summarise.

## 6. Tests

`php tests/run.php` (PHP CLI only; no network, DB or API key; `python3` for the scorer self-test). Covers: config and model resolution, request shapes for Luna/Terra/legacy models, rejected-parameter fallback, malformed/empty/truncated/refused/5xx/401/quota responses, cost math, budgets, chunking (short, one-hour, 260k-word, unpunctuated), summaries (short, one-hour, end-of-video facts, exceptionally long, hierarchical merge, failing section), Pass 0 (chunking, truncation, changed dose, dropped content, medical terms, raw preserved), duplicate/locked/stale/failed jobs and resume, a full mocked pipeline run, description safety, regenerate guards. The mocks simulate model behaviour, so these prove our handling, not model quality. **Unverified:** real Luna/Terra responses (parameter acceptance, reasoning-token usage, output quality), the real MySQL queries (the suite uses an in-memory fake DB; migration 2032 has not been run), the admin UI buttons in a browser, and live performance.

## 7. Safely testing ONE video before re-enabling anything

Keep `transcript_system` **off** and do not start any cron. Use a video that already has a completed transcript, so Whisper is never involved. (Pushing `main` deploys the test site, so merge only when you are ready for that.)

1. Review and merge the PR to the test site only (not `super`). On the **test** database back up `video_transcripts`, `video_seo_articles`, `seo_pipeline_logs`, `seo_pipeline_queue`, and `videos` (or at least the target row).
2. Run `migrations/2032_ai_usage_and_job_locks.sql` on the **test** database.
3. Confirm: Transcription system = off; "Rewrite video descriptions automatically" = off; no cron entries for `transcribe-cron.php` / `seo-pipeline-cron.php`.
4. Transcribe Videos → **Test OpenAI** (one tiny call). Confirm it names `gpt-6-luna`; fix the model/parameters here if the API objects.
5. Pick one video (note its internal ID, current description, summary, takeaways). Open the watch page and screenshot it.
6. **Regenerate ONE video's summary** with "replace existing" ticked. Check: success message shows `single`, 1 call, cost about $0.002; summary and takeaways cover the start, middle and end; no invented numbers; `plain_text` and the VTT unchanged; description unchanged.
7. SEO Articles → open that video → run steps one at a time with **Run step** (no cron). After each, check status and the Logs (model, tokens, cost). Pass 0: confirm `transcript_quality.fallback_chunks` is empty (or read the warnings), then compare `cleaned_transcript` with the raw for doses/lab values.
8. Read the final article for medical accuracy; read `usage` totals. Expect roughly $0.10-0.15; stop and investigate if it is well above that.
9. Only after that: decide on a second video, then a small batch with a low per-batch limit, watching cost. Enable cron last.

Rollback: restore the backups; revert the PR. The description backup (`description_backup`) holds the original if a description was applied.
