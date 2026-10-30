#!/bin/sh
# Vercel Ignored Build Step for repos whose Vercel project builds through Git.
# Vercel convention: exit 0 = skip the build, exit 1 = build.
# Every uncertain path builds. Skipping is the only outcome that must be proven:
# nothing outside Continuity's own files changed since the last successful
# deployment. Continuity's files are .continuity/ (checkpoints, plans, scripts)
# and its three skills, which tooling updates also replace.
# NOT enabled by default. See .continuity/README.md before wiring it in.
PREV="${VERCEL_GIT_PREVIOUS_SHA:-}"
[ -n "$PREV" ] || exit 1                                        # first deploy, or variable absent
git cat-file -e "$PREV^{commit}" 2>/dev/null || exit 1          # shallow clone lacks it
git merge-base --is-ancestor "$PREV" HEAD 2>/dev/null || exit 1 # force-push or unrelated history
if git diff --quiet "$PREV" HEAD -- . ':(exclude).continuity' \
    ':(exclude).agents/skills/handoff' ':(exclude).agents/skills/pickup' ':(exclude).agents/skills/continuity-onboard' \
    ':(exclude).claude/skills/handoff' ':(exclude).claude/skills/pickup' ':(exclude).claude/skills/continuity-onboard' \
    2>/dev/null; then
  echo "continuity: only Continuity files changed since $PREV; skipping build"
  exit 0
fi
exit 1
