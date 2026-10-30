# Continuity (Stage 0)

Repo-local record of where each workstream stands, so Claude Code or Codex can pick up where the last session stopped. Plain files, POSIX shell hooks, one standard-library Python script. Works with no server and no network; a computer can optionally be connected to the Continuity dashboard (below). The scripts never push.

## Daily use

| You want to | Claude Code | Codex |
| --- | --- | --- |
| Save your place at a stopping point | `/handoff` (optionally `/handoff ws_xxxxxxxx`) | `$handoff`, or "use the handoff skill" |
| Resume work | `/pickup`, then pick a workstream | `$pickup` |
| Onboard existing plans (once per repo) | `/continuity-onboard` | `$continuity-onboard` |

`/pickup` is not called `/resume` because both agents already have a built-in `/resume` command for reopening a past conversation, which would shadow a skill of the same name.

Commands the skills run, also usable by hand (`sh .continuity/continuity help` lists all):

```sh
sh .continuity/continuity workstream new "Supabase cost fix" --branch main --next "Compare reads to baseline"
sh .continuity/continuity workstream list
sh .continuity/continuity handoff facts             # what git says right now
sh .continuity/continuity handoff new ws_xxxxxxxx --agent claude-code
sh .continuity/continuity handoff commit .continuity/checkpoints/<file>.md --summary "..."
sh .continuity/continuity pickup [ws_xxxxxxxx]
sh .continuity/continuity sync-plans
sh .continuity/continuity config nudge off          # silence the nudge; pulse still recorded
sh .continuity/continuity dashboard status          # dashboard connection of this computer (optional)
sh .continuity/continuity test                      # full test suite in throwaway repos
```

## What runs automatically

| Hook | When | Effect |
| --- | --- | --- |
| `UserPromptSubmit` | every prompt you send | writes `pulse.json` (local, per worktree); at most once per 20 min, and only when the tree changed since the last handoff and 3+ prompts have passed, adds one line to the agent's context suggesting a handoff at a natural stopping point |
| `PreCompact` | before context compaction | a user-visible warning if there is unrecorded work (neither agent lets this hook talk to the model) |
| `SessionEnd` | when a session ends | a local snapshot from git facts, at most ~1 s of git work, no network, no commit; skipped when nothing changed since the last handoff |

No hook can block, continue, or interrupt a turn. There is no `Stop` hook.

## Layout

```text
.continuity/
  continuity            command dispatcher
  config.yml            nudge, nudge_minutes, done_at_default, skills_mode, deploy_rule, secret_scan
  hooks.json            the one hook definition, merged into .claude/settings.json and .codex/hooks.json by init
  plans/<slug>.md       plan stubs: front matter + an outline derived from the original (body_path)
  workstreams/<slug>.md one per workstream; id ws_xxxxxxxx is permanent, branch is just an attribute
  checkpoints/*.md      immutable; front matter is machine-readable, body is for people and agents
  onboarding/           inventory.json, proposal.md/.json, report.md
  scripts/              hooks, continuity.py, vercel-ignore.sh, test.sh
.agents/skills/{handoff,pickup,continuity-onboard}/SKILL.md   read by Codex; linked into .claude/skills/ for Claude Code
<git-dir>/continuity/   per-worktree cache: pulse.json, last-handoff.json, nudge-at, auto/ snapshots (never committed)
```

## Dashboard (optional)

A computer connected to the Continuity dashboard sends each checkpoint right after `/handoff` commits it, so the dashboard shows it before you push. Connect once per computer: in the dashboard, Settings > Computers that send checkpoints gives a one-line `sh .continuity/continuity dashboard connect URL KEY` command; it covers every Continuity project on that computer.

- Sent: the checkpoint file and its workstream file, both secret-scanned, plus the repository name, branch and the local commit. Never code, diffs or any other file. Nothing is pushed and nothing deploys.
- The repository stays the record: once the commit is pushed, the dashboard replaces its copy with GitHub's (same checkpoint id), so nothing is duplicated.
- Offline or dashboard down: the handoff still succeeds; the checkpoint waits in `~/.continuity/outbox/` and goes with the next handoff or pickup in any project. A refused key keeps it waiting too.
- The key lives in `~/.continuity/dashboard.json` (readable only by you), never in a repository. `dashboard status`, `dashboard flush`, `dashboard send [FILE]`, `dashboard disconnect`; `config dashboard off` stops sending for one project.

## Rules the scripts enforce

- A plan with `body_path` is owned by its original document. The stub's outline is regenerated from it at every handoff, pickup and `sync-plans`; item ids survive rewording, new items get new ids, deleted items are kept under `removed:`. Editing a stub's derived outline is reported and reverted. A moved original is reported with the new path git found; `body_path` is never changed silently.
- Checkpoints carry `code_commit` (HEAD when the handoff ran), never their own commit. The commit that added a checkpoint is `git log --diff-filter=A -1 -- <file>`.
- The handoff commit is `git commit --only -- .continuity`: only `.continuity/` is committed, anything else you staged stays staged, uncommitted code is recorded in `dirty_files`, never committed.
- Refused with nothing committed: unknown workstream, an embedded `checkpoint_commit`, a `verified` claim, an empty `## Next step`, a key-shaped string (exit 4), an already-committed checkpoint.

## Deployment safety

Checkpoint commits touch only `.continuity/`, and tooling updates touch only `.continuity/` and the three Continuity skills (`.agents/skills/{handoff,pickup,continuity-onboard}` and their `.claude/skills/` links), but a push of them can still trigger a deploy. `sh .continuity/continuity init` inspects this repository's deploy configuration and prints proposals; it never writes deployment files itself:

- GitHub Actions workflows that run on push: add those paths under `on.push` as `paths-ignore` (`init` prints the exact list), so a push that changes only Continuity files is skipped and any other push runs as before.
- `.vercelignore` (CLI deploys): exclude `/.continuity/`, `/.agents/` and `/.codex/`.
- A Git-connected Vercel project: `"ignoreCommand": "sh .continuity/scripts/vercel-ignore.sh"` in `vercel.json` makes it the Ignored Build Step. It skips only when nothing outside Continuity's files changed since the last successful deployment, and builds whenever anything is uncertain. A `vercel.json` ignoreCommand overrides the one in the Vercel dashboard.

The first install itself also edits AGENTS.md/CLAUDE.md and the hook settings, so merging it deploys once even with these rules.

Do not use `[skip ci]` for handoff commits: in the head commit of a push it suppresses the workflow for the entire push, including earlier code commits. Record what was applied in this repository in `.continuity/project.md` under Notes.

## Install, disable, roll back

```sh
sh .continuity/continuity init            # shows every diff, asks before each write
sh .continuity/continuity config nudge off
sh .continuity/continuity hooks remove    # removes only the three continuity hook entries
sh .continuity/continuity uninstall       # hooks, skill links, instruction blocks, this worktree's state
sh .continuity/continuity uninstall --purge   # also git rm .continuity/ and the skills in one revertable commit
```

Codex runs project hooks only after you trust them: start `codex` in the repo, open `/hooks`, trust the three continuity entries. Editing a hook script changes its hash and Codex asks again.

Every write to the repository is a commit, so `git revert` of the `continuity:` commits is always a second rollback path. The per-worktree state is cache; deleting it loses nothing that was committed.

This tooling is distributed from the central `continuity-manager` project; `.continuity/TOOLING.json` records the installed version. Do not edit the files it lists here; change them there and update every repository from it.

Requirements: `git`, `sh`, `python3` (3.8+) for the skills and commands. The hooks themselves need only `git` and `sh`; without `python3` they still work.
