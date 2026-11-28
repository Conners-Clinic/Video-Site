# Backend traffic readiness implementation

Owner approved starting the reviewed implementation on November 28, 2026.
Source base: `9c7322893483109cc27cbd48f458d0a6b8afb13c`.
Work branch: `codex/traffic-readiness-20261128`; review: PR #14.

## Scope and release conditions

Preserve channel search, numbered search pages, short medical search terms, the
default theme's Trending section, notification/message behavior, caption access,
server-rendered transcripts, both configured CTAs, and private viewer state.
Optimize measured repeated work first. Full-page caching, new popularity tables,
search engine replacement, and production indexes require supporting measurements.
No new paid service or bulk transcription is part of this work.

## Plan

- [x] Confirm current main/super heads and preserve work on an isolated branch.
- [x] Run existing offline regression suites: 524 assertions pass on PHP 8.2.32.
- [x] Verify SSH and database connectivity using existing deployment secrets.
- [x] Verify staging and production have distinct connected database identities.
- [x] Inventory current server revisions, checkout state, and CLI/database versions.
- [ ] Preserve and reconcile deployment checkout differences without discarding work.
- [ ] Establish effective web runtime, scheduler, request/query baseline and staging test isolation.
- [ ] Repair fail-fast, exact-revision deployment and rehearse rollback.
- [x] Batch language/category metadata and reuse request-local reads (staging component comparison passed).
- [x] Introduce bounded card projections and batch displayed related/personal fields (component and template parity tests).
- [x] Debounce and bound search; preserve channels, numbered pages and relevant terms (fixtures pass; browser rehearsal pending).
- [x] Reuse transcript records and remove theme-specific unused homepage work.
- [ ] Add public data caching only with invalidation and current access checks.
- [ ] Bound playlists, Watch Later, comments and replies with navigation continuity.
- [ ] Control both notification transports and verify unattended publication before scheduler cutover.
- [ ] Apply measured route budgets/session-lock changes and inspect worker isolation.
- [ ] Review index plans and implement only justified migrations.
- [ ] Reduce unnecessary assets and media loading with AJAX-navigation verification.
- [ ] Run candidate regression and bounded staging traffic checks.
- [ ] Deploy the tested revision, verify production and document measured results/limits.

## Verified environment and current deployment blockers

Read-only workflow runs `46140848325` and `46141128851` succeeded. The SSH account
can read site configuration, connect to both databases, and has schema-level
SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/DROP grants. It can write the site
directories. No database mutations or application deployments were performed.

The observed CLI is PHP 8.4.27 and both databases report MariaDB 10.11.19.
The PHP-FPM web runtime is also 8.4.27. Before app startup it reports a 2048M
memory limit, 3600-second execution limit and file-backed sessions. OPcache
directives are unavailable. Startup then removes memory/time limits; route budgets
remain pending request measurements. This supersedes historical PHP/MySQL assumptions.
Both sites use YouPlay. Production transcription is off; staging transcription is
on, so test execution must prevent unintended external processing. The SSH user's
crontab was not available through `crontab -l`; hosting-managed schedules remain
unverified.

Production is detached at `1ac6695dc69fc7d72f52aa5929f621ec73623482` in an
unfinished rebase of `07ec37f96dd0c3f9dc0fca256a9d849b37be0a46` onto that commit.
The index reports 2,357 added files, three AU conflicts and one AA conflict.
Both the original snapshot and destination commit are ancestors of the reconciled
source base. This supports further comparison, not automatic deletion or reset.

Private, verified archives now preserve both Git directories, tracked working
files and local configuration outside the webroots. Normalized token comparisons
show staging's only changed public function is PT_GetMedia. A private three-way
merge of that change with candidate 8efb5ac is clean and lints; the diff remains
on the server. Production's function file matches its public HEAD. No application
deployment or rebase recovery has occurred.

Staging is on main at `bf4db4811b64b721ddebedfc1081686f2a6e36df`, with one local
change in `assets/includes/functions_one.php`. Preserve and understand it before
deploying. The successful preflight found a separate database, not proof that all
external services or jobs are isolated.

## Validation record

- PHP preflight lint and YAML parsing/branch trigger checks pass.
- Local preflight returns exit 2 when target sites are absent, as intended.
- Existing suites: 456 pipeline, 24 watch-library, 10 upload-menu and 34 exporter
  assertions pass. No database integration suite has been run against a site DB.
- Live preflight only reports allowlisted diagnostics; credentials, grants text,
  raw database contents, local patches and scheduler command lines stay private.

## Implemented candidate changes

- Language metadata is one query rather than 46 on the staging dataset. Categories
  use two queries rather than 15. The caption helper reuses its loaded transcript
  rather than reading it again. Exact output comparisons passed in run 46142817989.
- Search accepts 2–200 Unicode characters and at most 12 words. Header suggestions
  wait 300 ms, cancel obsolete requests, support composition/paste and ignore stale
  responses. Public/unlisted approval rules are shared across search handlers.
  Channel discovery and numbered pages remain. Literal `%`/`_` no longer expand
  as wildcards. No full-text engine switch is introduced.
- Suggestions select only link/title fields. Browse, home, category, channel and
  related cards batch public owner fields. The displayed layouts do not consume
  purchases, votes, resume state or comment totals; those remain in the full watch
  loader. Card caches contain public fields only and last for one request.
- Only the effective YouPlay theme skips unused Trending work. Its font metadata
  says `default`, so this decision uses the configured/session theme instead.

## Measured component results

| Staging component | Before queries | After queries | Exact output |
| --- | ---: | ---: | --- |
| Language menus, 23 languages | 46 | 1 | Equal |
| Category navigation, 15 displayed categories | 15 | 2 | Equal |
| Caption link with existing transcript | 2 | 1 | Equal |
| Ten guest cards, cold owner cache | 30 | 1 | Equal |
| Twenty guest cards, same request owner cache | 60 | 0 | Equal |

These are component measurements, not an HTTP load test or a capacity claim.
The production-version CLI also passed all existing suites and 46 read-helper
assertions. Card tests compare actual template HTML for both themes and guest/
signed-in fixtures at 10 and 20 items. Staging card/search comparisons passed in run 46145729825. Rife, PectaSol, IV,
B12 and 5-FU preserve the first 20 result IDs. 5-FU has no current staging result,
so its positive-match fixture still needs a disposable data test.

The candidate also includes 30-second visible polling with one request in flight,
error backoff and hidden-tab pause for both transports. Messages remain supported;
PHP polling is retained in Node mode because the PHP endpoint also performs
pending-payment and activity work. The browser publication fallback remains until
the independent schedule is observed live. A new CLI publisher uses a DB lock,
100-row batches, approval checks and clears completed schedule timestamps. Eight
MariaDB checks passed using connection-local temporary fixtures, with no app
records changed and no browser visit. The five-minute workflow is not active yet.

Index inventory found existing indexes on most investigated paths. Reply lookups
have only a primary key, and a recent view-ranking plan estimates 354,259 rows
through the existing (video_id,time) index. No index migration has been selected
or applied. Index-build timing, write/lock impact and recovery remain unmeasured.

## Next step

Run the candidate's read-only card/search comparisons, preserve checkout state
privately, and establish a safe staging release and rollback path. Production is
still unchanged and remains in its interrupted rebase.
