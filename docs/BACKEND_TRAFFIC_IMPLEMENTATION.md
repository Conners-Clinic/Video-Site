# Backend traffic readiness implementation

Owner approved starting the reviewed implementation on November 28, 2026.
Source base: `9c7322893483109cc27cbd48f458d0a6b8afb13c`.
Implementation: [PR #14](https://github.com/Conners-Clinic/Video-Site/pull/14), merged and deployed.
Publisher release check: [PR #15](https://github.com/Conners-Clinic/Video-Site/pull/15).

## Release status

The application changes first shipped at `e21bf933b2f8d1e9d4bd1a04a23a151cb13c34c7`.
Production now runs `ac3ed4c610def9308421836a1738d6b0bfda44ec`, adding the publisher
release check without changing application code. The follow-up staging release
[46151569074](https://github.com/Conners-Clinic/Video-Site/actions/runs/46151569074)
and production release [46151672761](https://github.com/Conners-Clinic/Video-Site/actions/runs/46151672761)
also passed. Documentation-only follow-up commits may put main ahead of super;
the application code is the same on both branches.
Staging deployment [46150774218](https://github.com/Conners-Clinic/Video-Site/actions/runs/46150774218)
and production deployment [46150906217](https://github.com/Conners-Clinic/Video-Site/actions/runs/46150906217)
succeeded. Both passed migrations, PHP/browser-controller suites, six HTTP/asset
smokes and a PHP-FPM runtime check. No schema/index changes were necessary.
Production recovery preserved all three local helper directories; staging retained
its private media customization. Both configuration hashes stayed unchanged.

Production browser checks passed: ten autocomplete suggestions, numbered search
page two, 2 eager/18 lazy result images, AJAX navigation to a watch page, both CTA
sections, transcript tab, loaded caption track and actual video playback. The player
advanced roughly ten seconds with readyState 4 and no media error, then was paused.
One browser error appeared in a tag-manager call stack (`scrollLeft` on null); it
did not interrupt these flows and was not traced to a new browser controller.

Independent production publisher run
[46151743305](https://github.com/Conners-Clinic/Video-Site/actions/runs/46151743305)
succeeded after the production release, reporting `status: ok`, `published: 0`,
`lock_busy: false`. No videos were due. This verifies the live CLI/SSH/database
execution path, while disposable fixtures verify actual publication transitions.
The five-minute timer is registered; timer-event delivery has not yet been observed.
The browser fallback remains enabled pending a dependable scheduled run history.

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
- [x] Preserve and reconcile deployment checkout differences without discarding work; production recovery and staging media preservation succeeded.
- [x] Establish effective web runtime, request/query baseline and staging fixture isolation; hosting scheduler/pool settings remain outside observed access.
- [x] Implement fail-fast exact-revision deployment, config-only migration runner, private backups and rollback; exercise staging rollback and recovery on a private production copy.
- [x] Batch language/category metadata and reuse request-local reads (staging component comparison passed).
- [x] Introduce bounded card projections and batch displayed related/personal fields (component and template parity tests).
- [x] Debounce and bound search; preserve channels, numbered pages and relevant terms (fixtures and staging/production browser checks pass).
- [x] Reuse transcript records and remove theme-specific unused homepage work.
- [x] Cache public metadata/owner fields within a request; retain current access checks. Defer cross-request/full-page caching after measured staging improvements.
- [x] Bound playlist windows, Watch Later, channel playlist lists, comment permalinks and replies with navigation continuity.
- [x] Control both notification transports, including message handling, hidden-tab pause and backoff.
- [x] Verify production unattended publication through the same CLI/serialized job; retain browser fallback while the five-minute timer builds a run history.
- [x] Apply measured viewer-route budgets and release the autocomplete session lock after security checks; preserve separate upload/job behavior. Hosting worker-pool settings remain unverified.
- [x] Review index plans; defer production index builds without representative build/lock evidence. Add a guarded migration entrypoint for future approved changes.
- [x] Add native thumbnail loading hints and verify search-to-watch-to-channel AJAX navigation, transcripts and playback in staging.
- [x] Run candidate regression, disposable MariaDB fixtures, guest browser checks and bounded staging concurrency bursts.
- [x] Deploy the tested application revision, verify production HTTP/assets/browser flows and document measured results/limits.

## Environment and resolved deployment differences

Read-only workflow runs `46140848325` and `46141128851` succeeded. The SSH account
can read site configuration, connect to both databases, and has schema-level
SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/DROP grants. It can write the site
directories. Those initial access preflights did not mutate application data or deploy code.

The observed CLI is PHP 8.4.27 and both databases report MariaDB 10.11.19.
The PHP-FPM web runtime is also 8.4.27. Before app startup it reports a 2048M
memory limit, 3600-second execution limit and file-backed sessions. The release-time
web probe reports OPcache disabled. Legacy startup removes memory/time limits; the
deployed changes now apply finite
limits only to measured viewer routes. This supersedes historical PHP/MySQL assumptions.
Both sites use YouPlay. Production transcription is off; staging transcription is
on, so test execution must prevent unintended external processing. The SSH user's
crontab was not available through `crontab -l`; hosting-managed schedules remain
unverified.

Before this release, production was detached at `1ac6695dc69fc7d72f52aa5929f621ec73623482` in an
unfinished rebase of `07ec37f96dd0c3f9dc0fca256a9d849b37be0a46` onto that commit.
The index reported 2,357 added files, three AU conflicts and one AA conflict.
Both the original snapshot and destination commit are ancestors of the reconciled
source base. Comparison against public Git blobs accounted for all tracked differences: the known
media .htaccess conflict was reproducible, and three tracked symlink locations
contained local helper directories. The guarded release preserved those directories
privately, archived the exact original Git/working state, aborted only the proven
rebase, and fast-forwarded to the release. Production is no longer mid-rebase.

Private, verified archives now preserve both Git directories, tracked working
files and local configuration outside the webroots. Normalized token comparisons
show staging's only changed public function is PT_GetMedia. A private three-way
merge of that change with candidate 8efb5ac is clean and lints; the diff remains
on the server. Production's function file matches its public HEAD. Temporary staging releases have been rolled back successfully. A private copy of
the production checkout has completed recovery and candidate checkout without changing
the served production checkout. Three local helper directories are preserved outside
the webroot during recovery; no private source or configuration enters GitHub logs.

Staging originally ran main at `bf4db4811b64b721ddebedfc1081686f2a6e36df`, with one local
change in `assets/includes/functions_one.php`. The deployed three-way merge retains
that customization. A separate staging database does not prove external services or
jobs are isolated; rehearsal/fixture tooling prevents unintended processing.

## Validation record

- PHP preflight lint and YAML parsing/branch trigger checks pass.
- Local preflight returns exit 2 when target sites are absent, as intended.
- Existing suites: 456 pipeline, 24 watch-library, 10 upload-menu and 34 exporter
  assertions pass. No database integration suite has been run against a site DB.
- Live preflight only reports allowlisted diagnostics; credentials, grants text,
  raw database contents, local patches and scheduler command lines stay private.

## Implemented application changes

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
B12 and 5-FU preserve the first 20 result IDs. 5-FU has no current staging result; disposable database fixtures additionally
exercise positive short-term matches, entity-encoded punctuation, literal wildcards
and visibility rules.

The candidate also includes 30-second visible polling with one request in flight,
error backoff and hidden-tab pause for both transports. Messages remain supported;
PHP polling is retained in Node mode because the PHP endpoint also performs
pending-payment and activity work. The browser publication fallback remains until
the independent schedule is observed live. A new CLI publisher uses a DB lock,
100-row batches, approval checks and clears completed schedule timestamps. Eight
MariaDB checks passed using connection-local temporary fixtures, with no app
records changed and no browser visit. The five-minute workflow is now on the default
branch, and its release-triggered production invocation passed. PR #15 also
runs that same serialized job after successful production Deploy workflows. Failed
and staging deployments cannot trigger it. Browser fallback removal is deferred.

Index inventory found existing indexes on most investigated paths. Reply lookups
have only a primary key, and a recent view-ranking plan estimates 354,259 rows
through the existing (video_id,time) index. No index migration has been selected
or applied. Index-build timing, write/lock impact and recovery remain unmeasured.

## Complete-request staging results

Candidate `75fa904` in run `46148865130` and candidate `2f5b764` in run
`46150152075` completed sequential guest requests, two/five-concurrent-request
bursts and rollback. Instrumented query counts include the two new session SQL
budget statements. The baseline has no such budget statements.

| Route | Baseline median queries | Candidate median queries |
| --- | ---: | ---: |
| Homepage | 305 | 129 |
| Search | 139 | 24 |
| Watch | 102 | 43 |
| Suggestions | 101 | 15 |

Every sampled route peaked at 8 MiB of PHP memory. Candidate `2f5b764` served the
five-concurrent-request burst with zero errors across 20 requests and 185 ms p95.
The earlier burst had zero errors and 170 ms p95. These short, same-host staging
checks do not establish sustained public capacity, CDN transfer performance or a
maximum concurrent-user count. Signed-in live HTTP and hosting worker-pool limits
have not been verified; signed-in template/visibility fixtures pass.

The browser review caught restrictive permissions on newly checked-out JavaScript.
A staging-only repair restored the three public assets to 0644. The rehearsal and
release process now use a public-readable umask for source checkout, keep private
journals at 0700/0600, and request each script directly during smoke checks.
After repair, desktop autocomplete, numbered search pages, lazy images, AJAX watch
and channel navigation, transcripts, playlist metadata and video playback passed.
The browser showed no horizontal overflow on the reviewed desktop pages. No
separate mobile browser viewport or signed-in account was exercised.

## Bounded collections and runtime

- Playlist and Watch Later sidebars load at most 50 items, retain the current
  position/count and supply the next playable neighbor across boundaries. Browsing
  another window retains the current video's autoplay target. Reordering changes
  only the submitted owned membership slots in a transaction; other items remain.
- Channel playlist pages show 20 playlists and obtain a count/first video without
  hydrating every member. Private, unapproved and blocked content remains scoped.
- Initial watch comments stay at five plus at most one focused permalink target.
  Replies show five at a time with ordinary previous/next links, including direct
  reply permalinks. Reply owners/votes are batched; blocked replies are excluded.
- Disposable connection-local MariaDB fixtures cover list boundaries, reorder
  ownership/duplicates, unavailable videos, reply paging and cross-video links.
  They do not alter application records. Browser watch requests can record ordinary
  views, so the HTTP rehearsal is not described as database read-only.
- Suggestions receive 64 MiB / 8 seconds and a three-second SQL budget. Measured
  home/watch/search/videos/timeline routes receive 128 MiB / 20 seconds and a
  five-second SQL budget. CLI, uploads, payments and job endpoints retain their
  separate behavior. PHP execution limits are not a substitute for FPM pool limits.
- Notification activity credit now uses bounded elapsed visible polling time so
  reducing polling frequency does not arbitrarily reduce the activity counter.

## Release safeguards and operational follow-through

`46150152019` recovered a private copy of the exact production checkout, preserved
its three helper directories, fast-forwarded to the candidate, and restored the
original archive successfully. It did not modify the served checkout or database.
The deployment runner requires matching source/release SHAs, a known branch/state,
a private verified archive, passing candidate suites/migrations and successful HTTP
smokes. Failures stop the release; rollback restores the original tracked checkout
and Git metadata, including a pre-existing interrupted operation when necessary.
Runtime cache invalidation is scoped to cached scripts within the deployed site.

No schema/index migration is selected for this release. The CLI runner loads server
`config.php`, uses a migration lock and checksum ledger when migrations exist, and
requires resumable, backward-compatible migrations. Empty plans do not create tables.

Cross-request page caching, a ranking summary table, a new search engine and new
production indexes remain conditional improvements, not unimplemented requirements
for the measured query reductions. Hosting-level worker isolation and sustained
load sizing require provider settings and a representative traffic target.

## Next step

The reviewed implementation is deployed and its immediate checks passed. Subsequent
capacity work should begin with provider FPM pool/worker settings and a representative traffic
target, then a controlled sustained test. Preserve the existing browser fallback
until the timer has a dependable run history. Live signed-in and a dedicated mobile
viewport review remain additional validation, not results established by these tests.
