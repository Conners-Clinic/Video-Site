---
name: pickup
description: Pick up where the last session left off. Lists unfinished continuity workstreams, then verifies the chosen one against git (drift since its checkpoint, branch state, uncommitted work, sessions that ended without a handoff, plan changes) before proposing next steps. Use when starting or returning to work, or when the user pastes a resume prompt or says "pick up", "resume work", or "where were we".
---

# Pickup: verify before resuming

Work from the repository root. Do not change code until the user confirms the plan.

## 1. Choose the workstream

- If the user named a workstream id (`ws_...`) or pasted a resume prompt that names one, use it.
- Otherwise run `sh .continuity/continuity pickup`. It prints the project overview: goal, unfinished workstreams (status, last activity, next step, anything due), the plan map (roadmap phases with the plans under each; completed plans collapsed, `sh .continuity/continuity map --all` expands them), recent activity and the project's context documents. Summarize it in plain language, lead with anything due or blocked on the user, and ask which workstream to resume unless the user already said. A project often has several unfinished workstreams; do not assume the most recent one is the whole project.

## 2. Verify

Run `sh .continuity/continuity pickup <workstream-id>`. It refreshes plan stubs from their original documents and prints, in a fixed order: staleness (checkpoint age and kind, code commits since its `code_commit`, branch state, recorded and current uncommitted files, sessions that ended without a handoff), plan documents that changed, plans in scope with checked counts, any waiting condition and whether it is due, and the checkpoint's next step.

Then read the checkpoint file it names. If the user's pasted prompt includes dashboard changes or corrections, treat them as newer than the checkpoint.

If the checkpoint records a test command, you may run it to confirm the current state.

## 3. Report and propose

Lead with discrepancies: anything where git or the working tree disagrees with the checkpoint, stated plainly (for example "the checkpoint says PR #41 is open; it was merged"). Then summarize where the workstream stands, and propose the next two or three concrete actions. Stop and wait for the user's go-ahead.

`kind: onboarding` and `kind: auto` checkpoints were written without an agent session's judgment; treat their claims as unverified. If the plan stubs were refreshed, say so; those changes are committed by the next handoff.
