# Video transcription (local Whisper + OpenAI SEO)

Batch transcription for self-hosted videos, optional SEO summaries via OpenAI, and watch-page **About / Transcript** tabs.

## Database setup

Run once in phpMyAdmin (**select database `3595_connersclinic` first**):

1. `migrations/2026_transcription.sql` — queue + transcript tables  
2. `migrations/2027_transcript_seo.sql` — `seo_summary`, description modes, OpenAI config  
3. `migrations/2029_video_key_takeaways.sql` — `key_takeaways` JSON on `video_transcripts`  
4. `migrations/2030_watch_page_cta_config.sql` — global watch-page top CTA in `config`  
5. `migrations/2031_watch_cta_secondary.sql` — secondary watch-page CTA in `config`
6. `migrations/2032_ai_usage_and_job_locks.sql` — per-call cost columns, queue claim timestamp, description backup (see `docs/AI_PIPELINE_MODERNIZATION.md`)

Fresh installs: run migrations on existing databases; defaults apply even before migration via PHP fallbacks.

## Server requirements

| Component | Requirement |
|-----------|-------------|
| Whisper (audio → text) | Python 3, `faster-whisper`, `shell_exec`, FFmpeg — typically needs SSH |
| OpenAI (SEO summary) | PHP `curl` + outbound HTTPS — often works on shared hosting |
| Cron | `transcribe-cron.php` on a schedule you choose (often every 2–5 minutes) |

```cron
*/2 * * * * curl -s "https://test-videos.connersclinic.com/transcribe-cron.php" > /dev/null 2>&1
```

There is no minimum interval in code. Avoid `* * * * *` (every minute) unless the server can handle overlapping runs while Whisper is still busy.

## Admin workflow

**Admin Panel → Tools → Transcribe Videos**

1. Enable **Transcription system**; configure Whisper/Python if available on server.  
2. Set **OpenAI API key**. Models are per stage and default from `content-pipeline/config/pipeline-models.json` (summary: `gpt-6-luna`); the old single "OpenAI model" setting is no longer used.  
3. Set **Clinic CTA** HTML (database descriptions), **Watch page CTA card** (headline, body, button, trust labels), and **description mode** (see below).  
4. **Test OpenAI** to confirm API access.  
5. Enqueue videos by **channel**; cron runs Whisper.  
6. After transcripts complete: **Generate SEO summaries** (OpenAI batch).  
7. Optionally **Apply descriptions** for rows that already have summaries.

## Description modes

| Mode | `videos.description` in database |
|------|----------------------------------|
| `replace_description` | Summary + clinic CTA only (replaces generic CTA text) |
| `append_summary` | Prepends summary + CTA above existing description |
| `display_only` | Unchanged; tabs on watch page only |

Full transcript is **never** written to `videos.description`.

Descriptions are **not** rewritten automatically after transcription any more (setting "Rewrite video descriptions automatically", default off). Use **Apply descriptions** deliberately; the original description is saved in `video_transcripts.description_backup` the first time (after migration 2032).

## Watch page layout

The YouPlay theme uses a compact layout below the player:

1. Title, metadata, creator and video actions. Embed, download and other secondary actions remain available under **More video actions**, with the same permission checks.
2. **Overview** and **Transcript** tabs, when a transcript exists. The overview shows three takeaways initially; **View all** reveals every remaining takeaway. **About this video** expands to the summary or existing description.
3. The **primary CTA** follows the selected information panel. Comments expand below it.
4. Related videos occupy the desktop sidebar or follow comments on mobile. The **secondary CTA** follows related videos. Playlist pages retain their existing playlist column.

Edit the two offers independently in **Admin → Tools → Transcribe Videos**, under “Watch page — primary CTA” and “Watch page — secondary CTA”. All existing `watch_cta_*` / `watch_cta2_*` values and HTML overrides remain in use. An empty secondary URL still falls back to the primary URL. The secondary HTML is moved between responsive slots, never cloned, so custom IDs and form state remain intact. No settings migration is needed.

The YouPlay summary no longer appends an additional generic clinic pitch; the two configured CTAs provide those calls to action. Stored descriptions and other themes are unchanged. Without the enhancement script, both CTAs and the full transcript remain available in the main column.

Captions: WebVTT at `/vtt/{public_video_id}`.

Meta / Open Graph descriptions use the SEO summary when available (~220 chars).

### Reprocess older videos for takeaways

**Admin Panel → Tools → Transcribe Videos → Generate SEO summaries** now also picks completed transcripts missing `key_takeaways`. Each run calls OpenAI again for that video (summary + takeaways) using the **complete** transcript. Process in batches of 10–25. To redo a single video (even one that already has a summary) use "Regenerate ONE video's summary"; it never re-runs Whisper and never touches the description.

## Server load monitoring

**Admin Panel → Tools → Transcribe Videos** includes a **Server load** panel (auto-refresh) and optional **email alerts** when 1-minute load per CPU exceeds your thresholds (default warning 0.85, critical 1.25).

- Samples are recorded at most every **2 minutes** (transcription cron or admin refresh).
- Alerts use the site SMTP settings; set **Alert email** or leave blank for the main site email.
- **Send test alert email** verifies delivery without waiting for high load.

Config keys are in `migrations/2028_transcript_load_monitor.sql` for existing installs.

## Troubleshooting

| Issue | Check |
|-------|--------|
| `#1046 No database selected` | Click database in phpMyAdmin sidebar before SQL |
| Jobs stay queued | Admin **Transcription cron** panel: last hit time, stuck count; **Run cron now**; server `curl` to `/transcribe-cron.php` |
| Jobs stay queued | `transcript_system` = on; crontab line above on the server |
| No SEO summary | OpenAI key; **Test OpenAI**; `video_transcripts.error_message` |
| Tabs not showing | Transcript `status = completed`; deploy latest theme files |
| Description not updated | Mode not `display_only`; run **Apply descriptions** |
