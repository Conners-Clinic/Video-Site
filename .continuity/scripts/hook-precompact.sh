#!/bin/sh
# PreCompact hook (Claude Code and Codex). Advisory only: emits a user-visible
# systemMessage when the tree has unrecorded changes. Neither agent lets
# PreCompact add model context, so this reminds the person, not the model.
# Always exits 0.
exec 2>/dev/null  # hooks stay silent on stderr; failures never reach the agent

cat >/dev/null 2>&1
root="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
cd "$root" 2>/dev/null || exit 0
[ -f .continuity/config.yml ] || exit 0
. "$root/.continuity/scripts/lib.sh" 2>/dev/null || exit 0
[ "$(cont_config nudge true)" = "true" ] || exit 0

status_out="$(cont_timeout 1 git status --porcelain -- . ':(exclude).continuity' 2>/dev/null)" || exit 0
[ -n "$status_out" ] || exit 0
printf '%s\n' '{"systemMessage": "[continuity] Context is about to be compacted with uncommitted work since the last handoff. Consider running the handoff skill before continuing."}'
exit 0
