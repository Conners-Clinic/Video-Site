---
name: handoff
description: Save a continuity checkpoint for the current workstream so any agent can pick up later. Use at a natural stopping point, when the user says "handoff", "save my place", or "checkpoint", or when a continuity nudge appears and the current task is done. Commits only .continuity/, never code, never pushes.
---

# Handoff: write a continuity checkpoint

Work from the repository root. All commands are `sh .continuity/continuity ...`.

## 1. Gather facts from git, not memory

Run `sh .continuity/continuity handoff facts`. It prints branch, HEAD, uncommitted files, code commits since the last handoff in this worktree, pull requests (when `gh` works), sessions that ended without a handoff, and the unfinished workstreams.

## 2. Pick the workstream

- If the user named one, use it.
- Else, if exactly one unfinished workstream matches the current branch and it is clearly what this session worked on, use it.
- Else ask the user which workstream this was, or offer to create one: `sh .continuity/continuity workstream new "Short title" --branch <branch> --plans <plan ids>`. Several workstreams may share a branch, including `main`; never pick by branch alone when that is ambiguous.

## 3. Update plan documents

For each checklist item this session finished, tick the box in the plan's **original** document (the file named by `body_path` in its `.continuity/plans/*.md` stub). Never edit a stub below its "derived from" marker. If an item's text needs fixing, fix it in the original. Do not commit code changes as part of the handoff.

## 4. Create and fill the checkpoint

Run `sh .continuity/continuity handoff new <workstream-id> --agent <claude-code|codex>`. It prints the new file's path with git facts already filled in. Edit that file:

- Front matter: set `plans` (plan ids touched), `item_claims` (for example `[{item: it_c3d4, rung: implemented, ref: "PR #41"}]`), `tests` (`{command: "npm test", exit: 0}` only if you ran it this session), and `waiting` when something needs time or an event (`{type: time, until: 2026-10-30, observing: "...", decide: "..."}`). Leave the git fact fields alone; the commit step overwrites them.
- Claim `implemented`, `tested` or `deployed` only with a commit, PR, test exit code or deploy URL to cite. Never claim `verified`; that rung needs an observation the user records.
- Fill every body section briefly and concretely. `## Next step` is required: one or two sentences someone can act on without the transcript. `## Resume prompt` should name this checkpoint's path, the branch, and the first thing to verify.
- Do not write `checkpoint_commit` anywhere.

## 5. Commit

Run `sh .continuity/continuity handoff commit <path> --summary "<= 60 chars>"`. It validates the file, syncs plan stubs from their originals, updates the workstream's `next_step` and status, scans for secrets, and commits only `.continuity/` (anything else you staged stays staged). If it reports errors, fix the file and run it again. If it reports possible secrets, remove them and re-run. It never pushes. If this computer is connected to the Continuity dashboard, the same step sends the checkpoint there (only the checkpoint and its workstream file) and prints a `Dashboard:` line; if the dashboard cannot be reached, the checkpoint waits and goes later. Either way the handoff itself has succeeded.

## 6. Then do this repository's own handoff

The checkpoint does not replace project-specific handoff rules. If `AGENTS.md`, `CLAUDE.md` or similar files require other updates at the end of work (for example a status document), do those as well, following their rules.

Report to the user: the commit, the workstream and its next step, the `Dashboard:` line if there was one, and any plan-sync changes or warnings the commit step printed.
