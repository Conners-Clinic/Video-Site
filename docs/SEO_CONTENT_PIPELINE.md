# SEO Content Pipeline

Separate from **transcription** (Whisper). Transcription writes `video_transcripts.plain_text` (raw). This pipeline **reads** that text and never overwrites it.

## Setup

1. Run migration: `migrations/2028_seo_content_pipeline.sql`
2. Ensure OpenAI API key is set under **Admin → Transcribe Videos**
3. Enable pipeline under **Admin → SEO Articles → Settings**
4. Cron (recommended every 15 minutes):

```bash
*/15 * * * * curl -s https://yoursite.com/seo-pipeline-cron.php
```

## Architecture

| Layer | Location |
|--------|----------|
| Prompt templates | `content-pipeline/prompts/*.md` |
| Standards | `content-pipeline/standards/*.json` |
| Model names, per-stage settings, prices, limits | `content-pipeline/config/pipeline-models.json` (only place model names live) |
| OpenAI client, budgets, chunking | `assets/includes/functions_ai.php`, `functions_ai_summary.php` |
| Version tracking | `content-pipeline/config/versions.json` |
| Orchestration | `assets/includes/functions_seo_pipeline.php` |
| Queue worker | `seo-pipeline-cron.php` |
| Admin UI | `admin-panel/pages/seo-articles/` |
| AJAX | `aj/ap/seo_pipeline_*` |

## Pipeline flow

1. **Pass 0** — Clean transcript (whole transcript, in token-sized chunks; every chunk checked for dropped text and changed numbers; a chunk that fails verification keeps its raw text and is flagged in `transcript_quality`) → `cleaned_transcript` (raw copied to `raw_transcript` once)
2. **Entity extraction** — JSON
3. **Entity comparison** — JSON
4. **Auto-merge** — Deterministic PHP (updates `entity-library.json`)
5. **Review queue** — JSON + rows in `entity_review_queue`
6. **Pass 1** — SEO blueprint (Markdown)
7. **Pass 2** — Draft article (Markdown)
8. **Pass 3** — SEO audit (JSON)
9. **Pass 4** — Final article (Markdown) → status `complete`

Watch page transcript tab shows **cleaned** transcript when available.

## Data per video

Stored in `video_seo_articles` (see user spec in project brief). All pass outputs retained for debugging and reruns.

## Reruns

If an article already exists, admin chooses: `pass_0`, `pass_1`, `pass_2`, `pass_4`, or cancel.

Failed runs: status `failed`, `failed_pass` set; re-queue **resumes at the failed pass** and keeps earlier outputs (no re-billing). A finished (`complete`) article is never re-queued without an explicit force/rerun. Only one worker can process a video at a time (named DB lock + atomic queue claim).

## Publishing (extension point)

Articles are **not** auto-published. When ready, status is `complete`. Future CMS publish:

```php
// Extension hook — implement when CMS integration exists
function PT_SeoPublishArticleToCms($video_id) {
    // Read PT_GetVideoSeoArticle($video_id)->final_article_markdown
    // Map [INTERNAL LINK: Topic] placeholders via entity library
    // Push to CMS; return array('ok' => bool, 'url' => '...')
}
```

## Internal links

Articles use placeholders: `[INTERNAL LINK: Rife machine]` — no fake URLs. Resolve at publish time from `entity-library.json`.

## Editing prompts / standards

Use **SEO Articles → Prompts & standards** or edit files directly. Saving bumps version in `content-pipeline/config/versions.json`; new runs store versions on the article row.
