#!/bin/sh
# SessionEnd hook (Claude Code and Codex). Best-effort local snapshot.
# Budget: about 1 s of git work (Codex caps SessionEnd at 3 s). No network,
# no git writes, no background processes left behind. Always exits 0.
exec 2>/dev/null  # hooks stay silent on stderr; failures never reach the agent

payload="$(cat 2>/dev/null)"
root="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
cd "$root" 2>/dev/null || exit 0
[ -f .continuity/config.yml ] || exit 0
. "$root/.continuity/scripts/lib.sh" 2>/dev/null || exit 0
state="$(cont_state_dir)" || exit 0
mkdir -p "$state/auto" 2>/dev/null || exit 0

agent="$(cont_agent "$payload")"
session="$(printf '%s' "$payload" | cont_json_field session_id)"
reason="$(printf '%s' "$payload" | cont_json_field reason)"
safe_session="$(printf '%s' "${session:-nosession}" | tr -c 'A-Za-z0-9_-' '_' | cut -c1-40)"
head="$(git rev-parse HEAD 2>/dev/null)"
branch="$(git branch --show-current 2>/dev/null)"

last_code=""; last_id=""; last_ckc=""; last_fp=""
if [ -f "$state/last-handoff.json" ]; then
  last_code="$(cont_json_field code_commit < "$state/last-handoff.json")"
  last_id="$(cont_json_field id < "$state/last-handoff.json")"
  last_ckc="$(cont_json_field checkpoint_commit < "$state/last-handoff.json")"
  last_fp="$(cont_json_field tree_fp < "$state/last-handoff.json")"
fi

# Run the two potentially slow commands in parallel under one 1 s watchdog.
tmp="$state/auto/.tmp.$$"
git status --porcelain -- . ':(exclude).continuity' > "$tmp.status" 2>/dev/null &
p1=$!
git diff --stat HEAD -- . ':(exclude).continuity' > "$tmp.diff" 2>/dev/null &
p2=$!
git diff HEAD --no-color --no-ext-diff -- . ':(exclude).continuity' > "$tmp.full" 2>/dev/null &
p3=$!
( sleep 1; kill -TERM "$p1" "$p2" "$p3" 2>/dev/null ) >/dev/null 2>&1 &
w=$!
wait "$p1"; r1=$?
wait "$p2"; r2=$?
wait "$p3"; r3=$?
kill "$w" 2>/dev/null; wait "$w" 2>/dev/null

# Nothing happened since the last handoff (same HEAD, same tree): no snapshot needed.
if [ "$r1" -eq 0 ] && [ "$r3" -eq 0 ] && [ -n "$last_ckc" ] && [ "$head" = "$last_ckc" ]; then
  cur_fp="$({ cat "$tmp.status"; echo "--"; cat "$tmp.full"; } | git hash-object --stdin 2>/dev/null)"
  if [ "$cur_fp" = "$last_fp" ]; then rm -f "$tmp.status" "$tmp.diff" "$tmp.full"; exit 0; fi
fi

commits=""
if [ -n "$last_code" ]; then
  commits="$(git log --oneline -n 50 "$last_code..HEAD" 2>/dev/null)"
fi

out="$state/auto/$(cont_stamp)-$safe_session.md"
{
  echo "---"
  echo "kind: auto"
  echo "agent: $agent"
  echo "session: \"$session\""
  echo "end_reason: \"$reason\""
  echo "created: $(cont_now_iso)"
  echo "branch: \"$branch\""
  echo "code_commit: $head"
  echo "worktree: \"$root\""
  echo "machine: \"$(hostname 2>/dev/null)\""
  echo "last_handoff_id: \"$last_id\""
  echo "---"
  echo "## Working tree status"
  if [ "$r1" -eq 0 ]; then
    if [ -s "$tmp.status" ]; then sed 's/^/    /' "$tmp.status"; else echo "clean"; fi
  else
    echo "unavailable"
  fi
  echo "## Diff stat"
  if [ "$r2" -eq 0 ]; then
    if [ -s "$tmp.diff" ]; then tail -n 1 "$tmp.diff" | sed 's/^ *//'; else echo "none"; fi
  else
    echo "unavailable"
  fi
  echo "## Commits since last handoff"
  if [ -n "$commits" ]; then printf '%s\n' "$commits" | sed 's/^/    /'; else echo "none recorded"; fi
} > "$out" 2>/dev/null
rm -f "$tmp.status" "$tmp.diff" "$tmp.full" 2>/dev/null
exit 0
