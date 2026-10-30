# Video Site: agent notes

Conners Clinic's self-hosted video platform (PlayTube-based PHP) at videos.connersclinic.com, with an admin panel, Whisper transcription and an SEO content pipeline (docs/TRANSCRIPTION.md, docs/SEO_CONTENT_PIPELINE.md).

- This repository is public: never commit secrets, server details beyond what the workflow already shows, or private notes.
- Pushing `main` deploys the test site and pushing `super` deploys the live site (`.github/workflows/deploy.yml`), except pushes that change only Continuity files.
- Other developers work here too; work on a branch and open a pull request.

<!-- continuity:start -->
## Continuity checkpoints (Stage 0)

This repository uses `.continuity/` to record where each workstream stands, so any agent can pick up where the last one stopped. It is in addition to this file's own end-of-work and handoff steps, never instead of them.

- At a natural stopping point, run the handoff skill (`/handoff` in Claude Code, `$handoff` in Codex). It writes a checkpoint and commits only `.continuity/`. Then still complete this repository's own handoff requirements (for example its status document).
- When starting or returning to work, run the pickup skill (`/pickup` in Claude Code, `$pickup` in Codex) to list unfinished workstreams and verify one against git before changing code.
- Tick checklist items in the plan's original document (named by `body_path` in its `.continuity/plans/` stub), never in the stub.
- The scripts never push. Checkpoint commits touch only `.continuity/`.
<!-- continuity:end -->
