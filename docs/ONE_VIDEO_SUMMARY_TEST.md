# First live test: one full-transcript summary (test site only)

Scope: the summary + Key Takeaways step for ONE already-transcribed public video. No Whisper, no audio, no SEO article pipeline, no cron, no description rewrite. Transcription stays **off**.

## Before anything

1. Confirm the test site is running the new code: pushes to `main` deploy the test site (`.github/workflows/deploy.yml`). The deploy of commit `9a79139` failed with an SSH timeout (GitHub Actions run 43370586379), so the test site may still run the old code. Do not push `super`.
2. Take a database backup of the test DB, then run `migrations/2032_ai_usage_and_job_locks.sql` on the **test** DB only (it fails harmlessly at its first statement if already applied; the code also works without it, with reduced cost reporting).
3. Admin: Transcription system = off; "Rewrite video descriptions automatically" = off; no cron entries for `transcribe-cron.php` / `seo-pipeline-cron.php`.

## Choose the video

Run query 5 in `docs/sql/summary_inventory.sql.txt` on the test DB. Pick a public video where the transcript is over 6,000 characters (so the old cut-off missed the ending), and where you can name one substantial point from the last third. Write down: internal ID, that end-point (one sentence), the current About text and Key Takeaways, and the watch-page URL.

## Steps

1. `docs/sql/summary_compare_restore.sql.txt`, block **A**: snapshot the old About + Key Takeaways (and fingerprints of what must not change). Screenshot the watch page.
2. Transcribe Videos → **Test OpenAI**. 1 call, a few tokens (hard-capped at $0.05). Must report model `gpt-6-luna`. If the API rejects a parameter the client strips it once and retries; if it still fails, stop and fix the parameter before going on.
3. Transcribe Videos → **Regenerate ONE video's summary**, enter the ID, tick "replace existing", leave "apply to description" unticked.
4. Expected result message: `single`, **1 call**, about $0.002-0.004. A longer video (about 13K transcript tokens per hour) is still one call. More than 1 call, or `multi`, means something is off: stop.
5. Block **B** (old vs new), block **C** (every column must read `same`). Reload the watch page and compare with the screenshot. Check `seo_pipeline_logs` for the call (model, tokens, `finish_reason = stop`, `cost_usd`).
6. Decide. To go back: block **D** restores the old values; then **E** drops the snapshot.

## Cost and call budget

| Call | Count | Estimate |
|---|---|---|
| Test OpenAI | 1 | under $0.001 |
| Summary + takeaways (whole transcript, Luna, reasoning low) | 1 | about $0.002 for a 1-hour video (input $0.10/M, output $0.50/M; reasoning tokens are the uncertainty) |
| **Total** | **2** | **about $0.003, expect under $0.01** |

Rejected-parameter retries are not billed. Ceilings if something loops: $2.00 and 80 calls per job (config), 600,000-token transcript cap. This is an estimate; the real number is in the log after the run.

## What to check

- **Coverage:** the new About and takeaways mention your end-point (and something from the start and middle). The old text probably does not.
- **Terminology:** drug, supplement, gene and test names spelled as in the transcript (e.g. BRCA1, CA 19-9, naltrexone); no new jargon the speaker did not use.
- **Accuracy:** every number, dose, date and percentage appears verbatim in the transcript (search for it); no claim, cure promise or advice that the speaker did not make; takeaways are 3-5 short bullets that agree with the About text.
- **Safety:** block C all `same`; description, transcript, captions (`/vtt/<id>` still plays) and any SEO article untouched.
- **Known gap:** if the model returns an About but no takeaways, the new About is saved and the old takeaways are kept (the page then shows mismatched text). Block D fixes it; a code change to make the pair all-or-nothing is a separate decision.

## Later rollout: read-only inventory

`docs/sql/summary_inventory.sql.txt` (SELECT only) counts public videos in four groups: missing transcript; transcript but missing About/takeaways; summary likely built from the first 6,000 characters (transcript longer than 6,000 characters); already fine. It also lists the largest gaps, estimates cost for groups 2-3, and explains group 1 (no row, failed, empty). No local copy of the production data exists (the repo's `playtube.sql` has no transcripts), so run it on the test DB, then on live with read-only access, when ready.

## Audio retranscription (separate, later)

Out of scope here. If considered, follow `docs/TRANSCRIPTION_ENGINE_EVALUATION.md`: compare local models (base, small, medium, large-v3, with the glossary prompt) against the original audio on 5-6 clips with human-corrected references, scoring word error rate, term recall and number accuracy, before replacing any transcript or caption. Never overwrite `plain_text` or the VTT for the one-video summary test.
