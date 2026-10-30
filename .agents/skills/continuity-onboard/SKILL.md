---
name: continuity-onboard
description: One-time onboarding of a repository's existing planning documents into .continuity/. Inventories plans, roadmaps, phased docs, checklists and issues, proposes how they relate (roadmap, implementing plans, independent plans, duplicates, superseded, stale), and waits for the user's approval before writing anything. Never modifies original documents.
---

# Continuity onboarding

Four steps. Steps 1 and 2 write only under `.continuity/onboarding/`. Step 4 runs only after the user approves. Original documents are never moved, edited or deleted.

## 1. Inventory (script)

Run `sh .continuity/continuity onboard inventory`. It scores every tracked Markdown and text file by planning signals (checklists, phase and plan headings, file names, location), adds first and last commit dates and the last author from git history, pulls GitHub issues when `gh` works, and writes `.continuity/onboarding/inventory.json`. Nothing is excluded by path; a low score only sorts a file lower.

## 2. Classify and relate (you)

Read the top documents by score in full (default 60; list the rest as not reviewed). Respect this repository's own rules about archives and evidence folders (for example, files an AGENTS.md says to preserve are read, never changed). For each document decide:

- `kind`: roadmap, feature, fix, audit, investigation, migration, launch, status, decisions, other.
- `status`: active, paused, done, stale (likely abandoned), superseded, unknown. Rules: every box checked and no commit in 30 days means done; no commit in 90 days with open boxes means stale; recent commits mean active. Prefer the document's own statements over the rules when they are explicit.
- Relationships: `parent` (the roadmap or umbrella plan), `implements` (a roadmap phase it delivers, as `slug/pN` or the phase title), `relates`, `superseded_by`. One roadmap per project; if two qualify, say so and pick the newer, the older marked superseded.
- Duplicates only on concrete signals: same normalized title, identical phase lists, one links to or copies the other, or a later checklist that is a superset of an earlier one.
- Which plans deserve a workstream now (active work with a next step), with `next_step` taken from the document's first open item and marked low confidence.
- Status and log documents (current-status files, decision logs, open-question lists) are context, not plans; propose them as `kind: status` or `decisions` with no workstream, or reject them.

Write two files:

- `.continuity/onboarding/proposal.md`: grouped for review (roadmap, plans implementing roadmap phases, independent plans, status and context documents, stale and duplicate candidates, not registered), one row per document with kind, status, relationships, confidence, and a one-line reason citing evidence.
- `.continuity/onboarding/proposal.json`: the same, machine-readable, every row `"decision": "pending"`. Shape:
  `{"version":1,"plans":[{"doc":"docs/x.md","slug":"x","title":"...","kind":"fix","status":"active","parent":"roadmap-slug","implements":["roadmap-slug/p3"],"relates":[],"superseded_by":null,"done_at_default":"implemented","reason":"...","confidence":"high|medium|low","decision":"pending","phase_status":{"p1":"done"},"workstream":{"title":"...","next_step":"...","status":"active","extra_plans":["other-slug"],"waiting":{"type":"time","until":"YYYY-MM-DD","observing":"...","decide":"..."}}}]}`. `phase_status` is optional and records phase state for documents that track it in prose rather than checkboxes.

Do not run apply. Commit the inventory and proposal only if the user wants to review them from another machine.

## 3. Review (the user)

Walk the user through the proposal in groups: roadmap first, then implementing plans, then independent plans, then stale and duplicate candidates. Record each decision in `proposal.json` as `approved`, `edited` (with the edits applied to the row) or `rejected`. Every row needs a decision before apply.

## 4. Apply (script, only after approval)

Run `sh .continuity/continuity onboard apply .continuity/onboarding/proposal.json`. It writes one stub per approved document under `.continuity/plans/` with `origin: onboarded` and `body_path` pointing at the original, a workstream and a `kind: onboarding` checkpoint for each row that has one, and `report.md` listing what was registered and rejected; verifies every original is byte-identical; and makes one `continuity: onboard` commit touching only `.continuity/`. It never pushes.
