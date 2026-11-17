# Kodara backfill: read-only inventory and audio sample exporter

`scripts/export_transcription_inventory.php` is a CLI-only, read-only tool. It rejects any web request before loading anything (404, no output). It runs only `SELECT` queries inside a `READ ONLY` transaction, never runs FFmpeg or any external command, and does not touch the transcription worker, the queue, the database content or site files.

## What the code does with the original audio (checked in `super`)

`PT_ProcessTranscriptJob` (`assets/includes/functions_transcribe.php`) resolves the uploaded video (`videos.video_location`, or downloads it to a temp `.mp4` if it is in remote storage), has FFmpeg extract mono 16 kHz PCM to `upload/temp/transcribe/audio_<db id>_<timestamp>.wav`, runs `scripts/transcribe_whisper.py` on that WAV, then saves only the VTT (`upload/transcripts/<video_id>.vtt`) and text. The WAV is deleted by `PT_CleanupTranscriptTemp` on success and on every failure path after it is created. **Audio is not retained.** Only a crashed or interrupted job could leave a WAV behind. The original media source of truth is `videos.video_location`.

The exporter therefore reports saved audio only when a leftover `audio_<db id>_*.wav` exists (files touched in the last 5 minutes are ignored, they may belong to a running job) or a file named `<video_id>.<ext>` sits in a directory passed with `--audio-dir`. Otherwise it reports the original media source.

## Temp copies in R2 (unverified)

After the hosting migration, the worker's temp files were found in the R2 bucket `videos` under `upload/temp/transcribe/`: `audio_<db id>_<unix ts>.wav` (extracted audio) and `video_<db id>_<unix ts>.mp4` (downloaded originals), often many copies per video from retried jobs. They are leftovers, not an archive, and nothing verifies them. Have someone with R2 credentials write a listing (names, sizes, dates only) and pass it with `--audio-manifest`:

```bash
rclone lsf --format "pst" r2:videos/upload/temp/transcribe/ > kodara-r2-audio-listing.csv
```

Per eligible video the inventory shows the best copy (largest, then newest) of each kind: `cached_wav_key/size/copies/check` and `cached_mp4_...`, plus `temp_copy_flag=unverified_temp_copy`. Checks are plausibility only: a WAV is `matches_duration` when its size is within 3% of 16 kHz mono 16-bit for the database duration (otherwise `shorter_than_duration`, `longer_than_duration`, `under_1MB`, `duration_unknown`); an MP4 is `matches_original_size` only if it equals the original's size. `unverified_temp_copies_<stamp>.csv` lists every temp object separately (all copies, flagged best or not), including ids that are not in the inventory. A video with no WAV anywhere stays eligible. For a sample whose copy is in R2 the sample report gives a `retrieve_command` (`rclone copyto r2:videos/<key> ./<video_id>.wav`) to run with your own credentials; the exporter never downloads from R2, holds no R2 keys, and exports no URLs. Do not paste R2 keys into chats or commit them.

## Why it does not load `assets/init.php`

`init.php` starts a session and loads `app_start.php`, which sets cookies, can redirect/exit for logged-in users, reads `$_SERVER['HTTP_HOST']`, and can write to the database (language rows, user language). There is no existing production CLI job in the repo (the cron scripts load `init.php` over HTTP). The exporter instead does what `app_start.php` does for the connection: it reads `config.php` and opens a `mysqli` connection with the same variables, then sets the transaction read only. Credentials are never printed; failures print only an error number.

## Rules

| Rule | Behaviour |
|------|-----------|
| Scope | Videos owned by the transcript channels in site config (`transcript_channel_usernames`, default `DrKevinConners,ConnersClinic`). `--all-channels` includes everything. |
| Private | `privacy` other than 0 (private or unlisted), or listed in `--exclude-ids-file`: always skipped (rule `private`). Nothing, including the Q&A list, overrides this. Titles that look patient-related get `review_flag=title_looks_patient_related` for you to check; they are not excluded by title alone. |
| Not self-hosted | Embeds, remote URLs, empty location, `converted=2`: skipped. Movies and shorts: skipped (same as the existing backfill). |
| Zoom | Title contains `ZOOM` as a whole word, case-insensitive (`Zoom call` matches, `Zoomer` does not): skipped (rule `full_zoom_call`). No exceptions, because no real Q&A clip has ZOOM in its title. |
| Q&A clips | Regular eligible videos. `--qa-ids-file` only fills the optional `qa_verified` column; `qa_title_hint` marks titles that look like Q&A. |
| Long videos | Longer than the worker's `transcript_max_duration` (default 7200 s): still eligible, flagged `over_worker_max_duration_<n>s` in `review_flag`. |

## Commands for the developer (on the server, as the site user)

Run from the site root. Replace the placeholders; the output directory must be outside the web root (the script refuses otherwise).

```bash
cd /path/to/site/public_html
umask 077
```

1. Optional: create `~/kodara-qa-ids.txt` with one verified Q&A `video_id` per line (`#` comments allowed). Optionally `~/kodara-exclude-ids.txt` for known patient recordings.

2. Inventory (default run):

```bash
php scripts/export_transcription_inventory.php --out-dir=$HOME/kodara-export --qa-ids-file=$HOME/kodara-qa-ids.txt --exclude-ids-file=$HOME/kodara-exclude-ids.txt
```

   Add `--audio-dir=/path` if audio was saved somewhere. Writes `inventory_<UTC stamp>.csv` (every video with eligibility and reason) and `skipped_<UTC stamp>.csv` (everything not eligible, sorted by rule, for spot-checking), and prints counts per rule.

3. Sample export (one regular video and one Q&A clip, by video_id):

```bash
php scripts/export_transcription_inventory.php --out-dir=$HOME/kodara-export --qa-ids-file=$HOME/kodara-qa-ids.txt --sample-ids=<REGULAR_VIDEO_ID>,<QA_VIDEO_ID>
```

   Copies saved audio to `kodara-export/samples/<video_id>.<ext>` (mode 0600) when it exists. Otherwise it writes the status `audio_not_retained` plus the original media source (storage path, size, whether it exists on disk) to `samples/sample_report_<stamp>.csv`. Private videos are refused.

4. Retrieve privately, from the developer's own machine over SSH (nothing is served by the web server):

```bash
scp -r <user>@<host>:kodara-export ./kodara-export
```

   Verify with `shasum -a 256` against `sha256` in the sample report, then remove the server copy:

```bash
rm -r $HOME/kodara-export
```

## Notes

- CSV cells starting with `= + - @` are prefixed with `'` so spreadsheets do not run titles as formulas.
- `source_ref` is a storage path or a URL with credentials and query string (signed parts) removed.
- Offline checks: `php tests/export_inventory_check.php` (no database needed).
- If the original media for a sample is only in remote storage (`local_path_not_on_disk`), the report names the storage path; fetching it is a separate step.
