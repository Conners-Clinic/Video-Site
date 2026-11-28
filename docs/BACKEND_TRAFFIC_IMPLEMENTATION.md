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
- [ ] Batch language/category metadata and reuse request-local reads.
- [ ] Introduce bounded card projections and batch displayed related/personal fields.
- [ ] Debounce and bound search; preserve channels, numbered pages and relevant terms.
- [ ] Reuse transcript records and remove theme-specific unused homepage work.
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
This supersedes historical PHP/MySQL assumptions. PHP-FPM is not yet verified.
Both sites use YouPlay. Production transcription is off; staging transcription is
on, so test execution must prevent unintended external processing. The SSH user's
crontab was not available through `crontab -l`; hosting-managed schedules remain
unverified.

Production is detached at `1ac6695dc69fc7d72f52aa5929f621ec73623482` in an
unfinished rebase of `07ec37f96dd0c3f9dc0fca256a9d849b37be0a46` onto that commit.
The index reports 2,357 added files, three AU conflicts and one AA conflict.
Both the original snapshot and destination commit are ancestors of the reconciled
source base. This supports further comparison, not automatic deletion or reset.

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

## Next step

Compare the interrupted rebase's staged/conflicted files with known public
commits and inspect the staging media-helper change. Preserve server-local state
before changing deployments, then establish the staging performance baseline.
