# Continuity Stage 0 shared shell helpers. POSIX sh only (macOS /bin/sh, dash, bash).
# Sourced by the hook scripts. Hooks must never fail the agent: every helper
# returns quietly on error and callers exit 0.

# Absolute repository root, or failure when not inside a work tree.
cont_root() {
  git rev-parse --show-toplevel 2>/dev/null
}

# Per-worktree state directory: $(git rev-parse --git-dir)/continuity.
# --git-dir is per-worktree (<common>/.git/worktrees/<name> for linked
# worktrees). Do NOT use --git-path info/...: git routes info/ to the shared
# common dir, so linked worktrees would share state.
cont_state_dir() {
  _d="$(git rev-parse --git-dir 2>/dev/null)" || return 1
  case "$_d" in /*) ;; *) _d="$(pwd)/$_d" ;; esac
  mkdir -p "$_d/continuity" 2>/dev/null || return 1
  printf '%s\n' "$_d/continuity"
}

# Read a scalar key from .continuity/config.yml (key: value). Prints default
# when the key or file is absent.
cont_config() {
  _key="$1"; _def="$2"; _file="$(cont_root)/.continuity/config.yml"
  _val=""
  if [ -f "$_file" ]; then
    _val="$(sed -n "s/^$_key:[[:space:]]*\([^#]*\).*/\1/p" "$_file" | head -n 1 | sed 's/[[:space:]]*$//; s/^"\(.*\)"$/\1/')"
  fi
  if [ -n "$_val" ]; then printf '%s\n' "$_val"; else printf '%s\n' "$_def"; fi
}

cont_now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }
cont_now_epoch() { date +%s; }
cont_stamp() { date -u +%Y%m%dT%H%M%SZ; }

# Extract a top-level string field from a small JSON document on stdin.
# Good enough for hook payloads and our own flat state files; not a JSON parser.
cont_json_field() {
  sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -n 1
}
cont_json_num() {
  sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p" | head -n 1
}

# JSON string escaping for values we write (backslash, quote, control chars).
cont_json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n\t\r' '   '
}

# Run a command with a time limit in whole seconds, without coreutils
# `timeout` (absent on macOS). Output goes to the caller's stdout. Returns the
# command's status, or 124 when it was killed.
cont_timeout() {
  _secs="$1"; shift
  "$@" &
  _pid=$!
  ( sleep "$_secs"; kill -TERM "$_pid" 2>/dev/null ) >/dev/null 2>&1 &
  _w=$!
  wait "$_pid"; _rc=$?
  kill "$_w" 2>/dev/null
  wait "$_w" 2>/dev/null
  if [ "$_rc" -eq 143 ]; then return 124; fi
  return "$_rc"
}

# Which agent invoked the hook. Claude Code sets CLAUDE_PROJECT_DIR for hook
# commands; Codex does not, and its payloads carry turn_id/model.
cont_agent() {
  _payload="$1"
  if [ -n "${CLAUDE_PROJECT_DIR:-}" ]; then echo claude-code; return; fi
  case "$_payload" in
    *'/.codex/'*|*'rollout-'*|*'"turn_id"'*|*'"model"'*) echo codex; return ;;
    *'/.claude/'*) echo claude-code; return ;;
  esac
  echo unknown
}

# Atomic write: content on stdin to file.
cont_write_atomic() {
  _tmp="$1.tmp.$$"
  cat > "$_tmp" && mv -f "$_tmp" "$1"
}

# Tree fingerprint: git hash-object of (status, "--", full diff vs HEAD), both
# excluding .continuity/. Changes whenever tracked content or the set of dirty
# and untracked files changes. continuity.py computes the identical value.
cont_tree_fp() {
  { git status --porcelain --untracked-files=normal -- . ':(exclude).continuity'
    echo "--"
    git diff HEAD --no-color --no-ext-diff -- . ':(exclude).continuity'
  } 2>/dev/null | git hash-object --stdin 2>/dev/null
}
