#!/bin/sh
# UserPromptSubmit hook (Claude Code and Codex).
# 1. Writes the per-worktree pulse file on every prompt (the dependable local record).
# 2. Prints at most one throttled nudge line, which both agents add to the model's context.
# Never blocks, never fails: always exits 0.
exec 2>/dev/null  # hooks stay silent on stderr; failures never reach the agent

payload="$(cat 2>/dev/null)"
root="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
cd "$root" 2>/dev/null || exit 0
[ -f .continuity/config.yml ] || exit 0
. "$root/.continuity/scripts/lib.sh" 2>/dev/null || exit 0
state="$(cont_state_dir)" || exit 0

now="$(cont_now_epoch)"
agent="$(cont_agent "$payload")"
session="$(printf '%s' "$payload" | cont_json_field session_id)"
head="$(git rev-parse --short=12 HEAD 2>/dev/null)"
branch="$(git branch --show-current 2>/dev/null)"

# git status can be slow on large trees; cap it at 1 s and drop the fields on timeout.
status_out="$(cont_timeout 1 git status --porcelain --untracked-files=normal -- . ':(exclude).continuity' 2>/dev/null)"; st=$?
if [ "$st" -eq 0 ]; then
  dirty_count="$(printf '%s' "$status_out" | grep -c . 2>/dev/null)"
  status_hash="$(printf '%s' "$status_out" | git hash-object --stdin 2>/dev/null)"
else
  dirty_count=""
  status_hash=""
fi

last_id=""; last_code=""; last_ckc=""; last_fp=""
if [ -f "$state/last-handoff.json" ]; then
  last_id="$(cont_json_field id < "$state/last-handoff.json")"
  last_code="$(cont_json_field code_commit < "$state/last-handoff.json")"
  last_ckc="$(cont_json_field checkpoint_commit < "$state/last-handoff.json")"
  last_fp="$(cont_json_field tree_fp < "$state/last-handoff.json")"
fi

prompts=0
if [ -f "$state/pulse.json" ]; then
  prev_last="$(cont_json_field last_handoff_id < "$state/pulse.json")"
  prev_n="$(cont_json_num prompts_since_handoff < "$state/pulse.json")"
  if [ "$prev_last" = "$last_id" ] && [ -n "$prev_n" ]; then prompts="$prev_n"; fi
fi
prompts=$((prompts + 1))

{
  printf '{ "at": "%s", "agent": "%s", "session": "%s",\n' "$(cont_now_iso)" "$agent" "$(cont_json_escape "$session")"
  printf '  "head": "%s", "branch": "%s",\n' "$head" "$(cont_json_escape "$branch")"
  printf '  "status_hash": "%s", "dirty_count": %s,\n' "$status_hash" "${dirty_count:-null}"
  printf '  "last_handoff_id": "%s", "prompts_since_handoff": %s }\n' "$last_id" "$prompts"
} | cont_write_atomic "$state/pulse.json" 2>/dev/null

# Nudge decision.
[ "$(cont_config nudge true)" = "true" ] || exit 0
changed=0
# Dirty files count as unrecorded only if the tree differs from what the last handoff recorded.
if [ -n "$dirty_count" ] && [ "$dirty_count" -gt 0 ]; then
  if [ -z "$last_fp" ]; then changed=1
  else
    fp="$(cont_timeout 1 sh -c '. "$1"; cont_tree_fp' _ "$root/.continuity/scripts/lib.sh")"
    [ "$fp" = "$last_fp" ] || changed=1
  fi
fi
if [ "$changed" -eq 0 ] && [ -n "$last_code" ] && [ -n "$head" ]; then
  case "$last_code" in "$head"*) ;; *)
    case "$last_ckc" in "$head"*) ;; *)
      # Commits since the last handoff that touch something besides .continuity/
      if ! git diff --quiet "$last_code" HEAD -- . ':(exclude).continuity' 2>/dev/null; then changed=1; fi
    ;; esac
  ;; esac
fi
[ "$changed" -eq 1 ] || exit 0
[ "$prompts" -ge 3 ] || exit 0

minutes="$(cont_config nudge_minutes 20)"
last_nudge=0
[ -f "$state/nudge-at" ] && last_nudge="$(cat "$state/nudge-at" 2>/dev/null)"
case "$last_nudge" in ''|*[!0-9]*) last_nudge=0 ;; esac
[ $((now - last_nudge)) -ge $((minutes * 60)) ] || exit 0

printf '%s\n' "$now" > "$state/nudge-at" 2>/dev/null
echo "[continuity] Uncommitted or unrecorded work since the last handoff. At a natural stopping point, run the handoff skill (/handoff in Claude Code, \$handoff in Codex). Do not interrupt the current task for this."
exit 0
