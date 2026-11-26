#!/bin/sh
# Continuity Stage 0 test suite. Builds throwaway repositories under a temp
# directory; never touches the repository it is run from.
#   sh .continuity/continuity test            all tests
#   sh .continuity/continuity test T8 T9      selected tests
# Requires git and python3. POSIX sh.

SRC="$(cd "$(dirname "$0")/../.." && pwd)"
TMPROOT="$(mktemp -d 2>/dev/null || mktemp -d -t continuity)"
PASS=0; FAIL=0; FAILED=""
ONLY="$*"
C="sh .continuity/continuity"
# Never use this computer's real dashboard connection or outbox.
CONTINUITY_HOME="$TMPROOT/home"; export CONTINUITY_HOME

cleanup() { rm -rf "$TMPROOT"; }
trap cleanup EXIT INT TERM

want() { [ -z "$ONLY" ] && return 0; case " $ONLY " in *" $1 "*) return 0 ;; esac; return 1; }
ok() { PASS=$((PASS + 1)); printf '  ok   %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILED="$FAILED $CUR"; printf '  FAIL %s\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
now_f() { python3 -c 'import time; print("%.3f" % time.time())'; }

mkrepo() {
  R="$TMPROOT/$1"
  rm -rf "$R"; mkdir -p "$R"; cd "$R" || exit 1
  git init -q
  git symbolic-ref HEAD refs/heads/main
  git config user.email test@example.com; git config user.name Test
  git config commit.gpgsign false
  echo "# App" > README.md; echo one > a.txt
  git add . && git commit -qm "initial"
  mkdir -p .continuity .agents
  cp -R "$SRC/.continuity/scripts" "$SRC/.continuity/hooks.json" "$SRC/.continuity/continuity" .continuity/
  cp "$SRC/.continuity/config.yml" .continuity/config.yml
  mkdir -p .continuity/plans .continuity/workstreams .continuity/checkpoints .continuity/onboarding
  cp -R "$SRC/.agents/skills" .agents/
  git add -A && git commit -qm "add continuity"
}

ws_new() { $C workstream new "$@" | head -n 1; }

# Fill required sections of a fresh checkpoint file.
fill_cp() {
  python3 - "$1" "${2:-Do the next thing.}" <<'EOF'
import sys
p, nxt = sys.argv[1], sys.argv[2]
t = open(p).read()
t = t.replace("## Objective\n\n", "## Objective\n\nTest objective.\n\n", 1)
t = t.replace("## Completed since last checkpoint\n\n", "## Completed since last checkpoint\n\nStuff.\n\n", 1)
t = t.replace("## Next step\n\n", "## Next step\n\n%s\n\n" % nxt, 1)
open(p, "w").write(t)
EOF
}

handoff() { # handoff WSID [next-step]
  f="$($C handoff new "$1" --agent test)" || return 1
  fill_cp "$f" "${2:-Do the next thing.}"
  $C handoff commit "$f" --summary "test handoff" > "$TMPROOT/last-handoff.out" 2>&1
}

prompt_hook() { # payload on $1
  printf '%s' "$1" | sh .continuity/scripts/hook-prompt.sh
}

echo "Continuity Stage 0 tests ($(git --version), sh=$(readlink /bin/sh 2>/dev/null || echo sh))"

# ---------------------------------------------------------------- T1
CUR=T1; if want T1; then echo "T1 worktree isolation"
  mkrepo t1
  git worktree add -q ../t1-wt -b feat
  prompt_hook '{"session_id":"main-s","hook_event_name":"UserPromptSubmit"}' >/dev/null
  (cd ../t1-wt && prompt_hook '{"session_id":"wt-s","hook_event_name":"UserPromptSubmit"}' >/dev/null)
  m="$(git rev-parse --git-dir)/continuity"
  w="$(cd ../t1-wt && git rev-parse --git-dir)/continuity"
  check "state dirs differ" '[ "$m" != "$(cd ../t1-wt && cd "$(git rev-parse --git-dir)" && pwd)/continuity" ]'
  check "main pulse belongs to main session" 'grep -q main-s "$m/pulse.json"'
  check "linked pulse belongs to linked session" 'grep -q wt-s "$w/pulse.json"'
  check "main cannot see linked pulse" '! grep -q wt-s "$m/pulse.json"'
  check "--git-path info/ would have been shared (documented defect)" '[ "$(cd ../t1-wt && git rev-parse --git-path info/x)" = "$(cd "$(git rev-parse --git-common-dir)" && pwd -P)/info/x" ]'
  git worktree remove --force ../t1-wt
  check "worktree removal deletes its state" '[ ! -d "$w" ]'
fi

# ---------------------------------------------------------------- T2
CUR=T2; if want T2; then echo "T2 paths-only commit"
  mkrepo t2
  id="$(ws_new "Feature A" --branch main)"
  echo change >> a.txt; git add a.txt
  handoff "$id"
  check "handoff succeeded" '[ $? -eq 0 ] && grep -q "Handoff committed" "$TMPROOT/last-handoff.out"'
  check "commit touches only .continuity/" '[ -z "$(git show --name-only --format= HEAD | grep -v "^\.continuity/")" ]'
  check "staged a.txt is still staged" 'git diff --cached --name-only | grep -qx a.txt'
  check "commit message prefix" 'git log -1 --format=%s | grep -q "^continuity: feature-a "'
fi

# ---------------------------------------------------------------- T3
CUR=T3; if want T3; then echo "T3 code_commit semantics"
  mkrepo t3
  id="$(ws_new "Feature B" --branch main)"
  handoff "$id"; h1="$(git rev-parse HEAD)"
  handoff "$id"
  cp2="$(ls -t .continuity/checkpoints/*.md | head -n 1)"
  check "second checkpoint code_commit is the first continuity commit" 'grep -q "^code_commit: $h1" "$cp2"'
  check "no checkpoint_commit written into files" '! grep -l "checkpoint_commit" .continuity/checkpoints/*.md >/dev/null'
  out="$($C pickup "$id")"
  check "zero drift after continuity-only commits" 'printf "%s" "$out" | grep -q "no code commits since the checkpoint"'
  echo code >> a.txt; git commit -qam "real code change"
  out="$($C pickup "$id")"
  check "one code commit of drift reported" 'printf "%s" "$out" | grep -q "1 code commit(s) since" && printf "%s" "$out" | grep -q "real code change"'
  cpc="$(git log --diff-filter=A --format=%H -1 -- "$cp2")"
  check "checkpoint commit derivable from git" '[ -n "$cpc" ] && [ "$cpc" != "$h1" ]'
fi

# ---------------------------------------------------------------- T4-T6
CUR=T4; if want T4 || want T5 || want T6; then echo "T4-T6 deploy rule"
  mkrepo t4
  A="$(git rev-parse HEAD)"
  echo code >> a.txt; git commit -qam "code"
  mkdir -p .continuity/checkpoints; echo x > .continuity/checkpoints/x.md; git add .continuity; git commit -qm "continuity: x"
  C1="$(git rev-parse HEAD)"
  CUR=T4; check "T4 code then checkpoint commit in one push builds" '! VERCEL_GIT_PREVIOUS_SHA=$A sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  echo y > .continuity/checkpoints/y.md; git add .continuity; git commit -qm "continuity: y"
  echo z > .continuity/checkpoints/z.md; git add .continuity; git commit -qm "continuity: z"
  CUR=T5; check "T5 continuity-only commits since last deploy skip" 'VERCEL_GIT_PREVIOUS_SHA=$C1 sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  C2="$(git rev-parse HEAD)"
  mkdir -p .claude/skills; ln -s ../../.agents/skills/handoff .claude/skills/handoff
  echo "# updated" >> .agents/skills/handoff/SKILL.md; echo "# updated" >> .continuity/scripts/lib.sh
  git add -A; git commit -qm "continuity: update tooling"
  check "T5 tooling update (skills and scripts) since last deploy skips" 'VERCEL_GIT_PREVIOUS_SHA=$C2 sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  C3="$(git rev-parse HEAD)"
  mkdir -p .agents/skills/other; echo x > .agents/skills/other/SKILL.md; git add -A; git commit -qm "other skill"
  check "T5 another skill folder builds" '! VERCEL_GIT_PREVIOUS_SHA=$C3 sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  git rm -rq .agents/skills/other; echo "# note" >> AGENTS.md; git add AGENTS.md; git commit -qm "agents note"
  check "T5 AGENTS.md change builds" '! VERCEL_GIT_PREVIOUS_SHA=$C3 sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  CUR=T6
  check "T6 empty previous SHA builds" '! VERCEL_GIT_PREVIOUS_SHA= sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  check "T6 unknown previous SHA builds" '! VERCEL_GIT_PREVIOUS_SHA=0123456789abcdef0123456789abcdef01234567 sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  git checkout -q -b side "$A"; echo s > s.txt; git add s.txt; git commit -qm side; S="$(git rev-parse HEAD)"; git checkout -q main
  check "T6 non-ancestor (force-push) builds" '! VERCEL_GIT_PREVIOUS_SHA=$S sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  PRE="$(git rev-parse HEAD)"
  git checkout -q -b feat; echo f > f.txt; git add f.txt; git commit -qm feat; git checkout -q main
  git merge -q --no-ff -m "merge feat" feat
  echo w > .continuity/checkpoints/w.md; git add .continuity; git commit -qm "continuity: w"
  check "T6 merge bringing code builds" '! VERCEL_GIT_PREVIOUS_SHA=$PRE sh .continuity/scripts/vercel-ignore.sh >/dev/null'
  cp="$(git rev-parse HEAD)"
  check "T6 HEAD equals previous SHA skips (redeploy of same tree)" 'VERCEL_GIT_PREVIOUS_SHA=$cp sh .continuity/scripts/vercel-ignore.sh >/dev/null'
fi

# ---------------------------------------------------------------- T7
CUR=T7; if want T7; then echo "T7 init does not clobber"
  mkrepo t7
  printf '{\n  "ignoreCommand": "bash scripts/own-ignore.sh"\n}\n' > vercel.json
  mkdir -p .claude .github/workflows
  printf '{\n  "permissions": {"allow": ["Bash(npm run *)"]},\n  "hooks": {"UserPromptSubmit": [{"hooks": [{"type": "command", "command": "echo hi"}]}]}\n}\n' > .claude/settings.json
  printf 'on:\n  push:\n    branches: [main]\njobs: {}\n' > .github/workflows/deploy.yml
  printf '# Agents\n\nExisting rule.\n' > AGENTS.md
  printf 'Read AGENTS.md\n' > CLAUDE.md
  git add -A; git commit -qm "existing config"
  vh="$(git hash-object vercel.json)"; wh="$(git hash-object .github/workflows/deploy.yml)"
  out="$($C init --yes 2>&1)"
  check "vercel.json untouched" '[ "$(git hash-object vercel.json)" = "$vh" ]'
  check "workflow untouched" '[ "$(git hash-object .github/workflows/deploy.yml)" = "$wh" ]'
  check "existing ignoreCommand reported, not replaced" 'printf "%s" "$out" | grep -q "already has ignoreCommand"'
  check "workflow paths-ignore proposed" 'printf "%s" "$out" | grep -q "paths-ignore"'
  check "proposal covers the skills too" 'printf "%s" "$out" | grep -q "\.agents/skills/handoff/\*\*" && printf "%s" "$out" | grep -q "\.claude/skills/continuity-onboard"'
  check "existing hook kept" 'grep -q "\"echo hi\"" .claude/settings.json'
  check "existing permissions kept" 'grep -q "Bash(npm run \*)" .claude/settings.json'
  check "three continuity hooks added" '[ "$(grep -c "continuity/scripts/hook-" .claude/settings.json)" -eq 3 ]'
  check "settings backup written" '[ -f .claude/settings.json.bak ]'
  check "codex hooks written" '[ "$(grep -c "continuity/scripts/hook-" .codex/hooks.json)" -eq 3 ]'
  check "skills linked" '[ -f .claude/skills/handoff/SKILL.md ] && [ -f .claude/skills/pickup/SKILL.md ]'
  check "AGENTS.md original text preserved" 'grep -q "Existing rule." AGENTS.md && grep -q "continuity:start" AGENTS.md'
  $C init --yes >/dev/null 2>&1
  check "init is idempotent" '[ "$(grep -c "continuity/scripts/hook-" .claude/settings.json)" -eq 3 ] && [ "$(grep -c "continuity:start" AGENTS.md)" -eq 1 ]'
  printf 'not json' > .codex/hooks.json
  out="$($C init --yes 2>&1)"
  check "invalid JSON left alone" '[ "$(cat .codex/hooks.json)" = "not json" ] && printf "%s" "$out" | grep -q "not valid JSON"'
  rm -f .codex/hooks.json
fi

# ---------------------------------------------------------------- T8-T10, T19
plan_fixture() {
  mkdir -p docs
  cat > docs/plan.md <<'EOF'
# Cost fix plan

Intro text.

## Phase 1: Diagnose
- [x] Capture baseline query counts
- [ ] Identify the N+1 in the episode list

## Phase 2: Fix and observe
- [ ] Batch episode metadata fetch (PR #41)
- [ ] Old retry wrapper
- [ ] Compare 72h usage to baseline
EOF
  cat > docs/other.md <<'EOF'
# Some notes
- [ ] unrelated
EOF
  cat > docs/dup.md <<'EOF'
# Cost fix plan (old)
## Phase 1: Diagnose
- [ ] Capture baseline query counts
EOF
  git add docs; git commit -qm "docs"
}
proposal() { # $1 = decision for dup
  cat > .continuity/onboarding/proposal.json <<EOF
{"version": 1, "plans": [
 {"doc": "docs/plan.md", "slug": "cost-fix", "title": "Cost fix plan", "kind": "fix", "status": "active",
  "parent": null, "implements": [], "relates": [], "superseded_by": null, "reason": "test", "decision": "approved",
  "workstream": {"title": "Cost fix", "next_step": "Identify the N+1", "status": "active"}},
 {"doc": "docs/dup.md", "slug": "cost-fix-old", "title": "Cost fix plan (old)", "kind": "fix", "status": "superseded",
  "superseded_by": "cost-fix", "reason": "older copy", "decision": "$1"},
 {"doc": "docs/other.md", "slug": "notes", "title": "Notes", "kind": "other", "status": "unknown",
  "reason": "not a plan", "decision": "rejected"}
]}
EOF
}

CUR=T19; if want T19 || want T8 || want T9 || want T10; then echo "T19 onboarding apply"
  mkrepo t8
  plan_fixture
  h_plan="$(git hash-object docs/plan.md)"; h_dup="$(git hash-object docs/dup.md)"; h_other="$(git hash-object docs/other.md)"
  proposal pending
  before="$(git rev-parse HEAD)"
  $C onboard apply .continuity/onboarding/proposal.json --yes >/dev/null 2>&1; rc=$?
  check "pending decisions refuse apply" '[ $rc -eq 2 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  proposal approved
  $C onboard apply .continuity/onboarding/proposal.json --yes > "$TMPROOT/apply.out" 2>&1; rc=$?
  check "apply succeeded" '[ $rc -eq 0 ]'
  check "exactly one onboarding commit" '[ "$(git rev-list --count $before..HEAD)" -eq 1 ] && git log -1 --format=%s | grep -qx "continuity: onboard"'
  check "commit touches only .continuity/" '[ -z "$(git show --name-only --format= HEAD | grep -v "^\.continuity/")" ]'
  check "originals byte-identical" '[ "$(git hash-object docs/plan.md)" = "$h_plan" ] && [ "$(git hash-object docs/dup.md)" = "$h_dup" ] && [ "$(git hash-object docs/other.md)" = "$h_other" ]'
  check "stub references original" 'grep -q "^body_path: docs/plan.md" .continuity/plans/cost-fix.md'
  check "superseded_by resolved to plan id" 'grep -q "^superseded_by: pl_" .continuity/plans/cost-fix-old.md'
  check "rejected doc in report" 'grep -q "docs/other.md" .continuity/onboarding/report.md && [ ! -f .continuity/plans/notes.md ]'
  check "workstream and onboarding checkpoint created" 'grep -q "^kind: onboarding" .continuity/checkpoints/*.md && ls .continuity/workstreams/cost-fix.md >/dev/null'
  check "phases parsed" '[ "$(grep -c "^## Phase" .continuity/plans/cost-fix.md)" -eq 2 ]'
fi

CUR=T8; if want T8 || want T9 || want T10; then echo "T8 plan sync, linked original"
  cd "$TMPROOT/t8" || exit 1
  stub=.continuity/plans/cost-fix.md
  id_n1="$(sed -n 's/.*Identify the N+1 in the episode list  {\(it_[a-z0-9]*\)}.*/\1/p' $stub)"
  id_retry="$(sed -n 's/.*Old retry wrapper  {\(it_[a-z0-9]*\)}.*/\1/p' $stub)"
  id_cmp="$(sed -n 's/.*Compare 72h usage to baseline  {\(it_[a-z0-9]*\)}.*/\1/p' $stub)"
  python3 - <<'EOF'
p = "docs/plan.md"
t = open(p).read()
t = t.replace("- [ ] Identify the N+1 in the episode list", "- [x] Identify the N+1 in the episode list")
t = t.replace("- [ ] Compare 72h usage to baseline", "- [ ] Compare 72h usage against the baseline")
t = t.replace("- [ ] Old retry wrapper\n", "")
t += "- [ ] Write the follow-up note\n"
open(p, "w").write(t)
EOF
  out="$($C sync-plans)"
  check "ticked item checked, same id" 'grep -q "\[x\] Identify the N+1 in the episode list  {$id_n1}" $stub'
  check "reworded item keeps id" 'grep -q "Compare 72h usage against the baseline  {$id_cmp}" $stub'
  check "new item gets new id" 'grep -q "Write the follow-up note  {it_" $stub && ! grep -q "Write the follow-up note  {$id_cmp}" $stub'
  check "deleted item listed under removed" 'grep -q "^- $id_retry: \"Old retry wrapper\"" $stub'
  check "source_hash updated" 'grep -q "^source_hash: $(git hash-object docs/plan.md)" $stub'
  check "report lists changes" 'printf "%s" "$out" | grep -q "item reworded" && printf "%s" "$out" | grep -q "item removed"'
  out="$($C sync-plans)"
  check "second sync is a no-op" 'printf "%s" "$out" | grep -q "all plan stubs current"'
fi

CUR=T9; if want T9 || want T10; then echo "T9 stub edited directly"
  cd "$TMPROOT/t8" || exit 1
  stub=.continuity/plans/cost-fix.md
  sed 's/Capture baseline query counts  {/Capture BOGUS counts  {/' $stub > $stub.new && mv $stub.new $stub
  out="$($C sync-plans)"
  check "direct stub edit reported" 'printf "%s" "$out" | grep -q "stub edited directly"'
  check "derived outline restored from original" 'grep -q "Capture baseline query counts  {" $stub && ! grep -q BOGUS $stub'
fi

CUR=T10; if want T10; then echo "T10 moved original"
  cd "$TMPROOT/t8" || exit 1
  git add -A; git commit -qm "sync"
  git mv docs/plan.md docs/plan2.md
  out="$($C sync-plans)"; rc=$?
  check "missing source exits 3" '[ $rc -eq 3 ]'
  check "new path proposed from git" 'printf "%s" "$out" | grep -q "moved to docs/plan2.md"'
  check "body_path not changed automatically" 'grep -q "^body_path: docs/plan.md" .continuity/plans/cost-fix.md'
  git mv docs/plan2.md docs/plan.md
fi

# ---------------------------------------------------------------- T11
CUR=T11; if want T11; then echo "T11 checkpoint validation"
  mkrepo t11
  id="$(ws_new "Val" --branch main)"
  before="$(git rev-parse HEAD)"
  f="$($C handoff new "$id" --agent test)"; fill_cp "$f"
  sed 's/^workstream: .*/workstream: ws_doesnotexist/' "$f" > "$f.n" && mv "$f.n" "$f"
  $C handoff commit "$f" >/dev/null 2>&1; rc=$?
  check "unknown workstream refused" '[ $rc -eq 2 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  rm -f "$f"
  f="$($C handoff new "$id" --agent test)"; fill_cp "$f"
  printf 'x' >/dev/null; sed 's/^kind: agent/kind: agent\ncheckpoint_commit: abc/' "$f" > "$f.n" && mv "$f.n" "$f"
  python3 - "$f" <<'EOF'
import sys; p=sys.argv[1]; t=open(p).read()
if "checkpoint_commit" not in t: t=t.replace("kind: agent\n","kind: agent\ncheckpoint_commit: abc\n",1)
open(p,"w").write(t)
EOF
  $C handoff commit "$f" >/dev/null 2>&1; rc=$?
  check "embedded checkpoint_commit refused" '[ $rc -eq 2 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  rm -f "$f"
  f="$($C handoff new "$id" --agent test)"; fill_cp "$f"
  sed 's/^item_claims: \[\]/item_claims: [{item: it_abcd, rung: verified, ref: "PR #1"}]/' "$f" > "$f.n" && mv "$f.n" "$f"
  $C handoff commit "$f" >/dev/null 2>&1; rc=$?
  check "verified claim refused" '[ $rc -eq 2 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  rm -f "$f"
  f="$($C handoff new "$id" --agent test)"
  $C handoff commit "$f" >/dev/null 2>&1; rc=$?
  check "empty next step refused" '[ $rc -eq 2 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  fill_cp "$f"; $C handoff commit "$f" >/dev/null 2>&1
  $C handoff commit "$f" >/dev/null 2>&1; rc=$?
  check "committed checkpoint is immutable" '[ $rc -eq 2 ]'
fi

# ---------------------------------------------------------------- T12
CUR=T12; if want T12; then echo "T12 secret scan"
  mkrepo t12
  id="$(ws_new "Sec" --branch main)"
  before="$(git rev-parse HEAD)"
  f="$($C handoff new "$id" --agent test)"; fill_cp "$f" "Rotate key sk-live-abcdefghijklmnopqrstuvwx123"
  $C handoff commit "$f" > "$TMPROOT/sec.out" 2>&1; rc=$?
  check "secret blocks commit with exit 4" '[ $rc -eq 4 ] && [ "$(git rev-parse HEAD)" = "$before" ]'
  check "file left for editing" '[ -f "$f" ] && grep -q "Possible secrets" "$TMPROOT/sec.out"'
fi

# ---------------------------------------------------------------- T13
CUR=T13; if want T13; then echo "T13 SessionEnd time budget"
  mkrepo t13
  mkdir -p "$TMPROOT/slowbin"
  realgit="$(command -v git)"
  cat > "$TMPROOT/slowbin/git" <<EOF
#!/bin/sh
case " \$* " in *" status "*|*" diff "*) exec sleep 10 ;; esac
exec "$realgit" "\$@"
EOF
  chmod +x "$TMPROOT/slowbin/git"
  t0="$(now_f)"
  printf '{"session_id":"slow","hook_event_name":"SessionEnd","reason":"other"}' | PATH="$TMPROOT/slowbin:$PATH" sh .continuity/scripts/hook-sessionend.sh; rc=$?
  t1="$(now_f)"
  el="$(python3 -c "print($t1-$t0)")"
  sd="$(git rev-parse --git-dir)/continuity/auto"
  check "exits 0" '[ $rc -eq 0 ]'
  check "finishes under 2.5 s with hung git (took ${el}s)" 'python3 -c "import sys; sys.exit(0 if $el < 2.5 else 1)"'
  check "snapshot written with unavailable status" 'grep -q "^unavailable" "$sd"/*-slow.md'
  check "no commit made" '[ "$(git log -1 --format=%s)" = "add continuity" ]'
fi

# ---------------------------------------------------------------- T14 / T15
CUR=T14; if want T14 || want T15; then echo "T14 nudge throttle"
  mkrepo t14
  echo dirty >> a.txt
  n=0; i=0
  while [ $i -lt 6 ]; do
    out="$(prompt_hook '{"session_id":"s14","hook_event_name":"UserPromptSubmit","turn_id":"t","model":"m"}')"
    [ -n "$out" ] && n=$((n + 1)); i=$((i + 1))
  done
  sd="$(git rev-parse --git-dir)/continuity"
  check "nudge printed exactly once" '[ $n -eq 1 ]'
  check "pulse counts six prompts" 'grep -q "\"prompts_since_handoff\": 6" "$sd/pulse.json"'
  check "codex payload detected" 'grep -q "\"agent\": \"codex\"" "$sd/pulse.json"'
  check "dirty count recorded" 'grep -q "\"dirty_count\": 1" "$sd/pulse.json"'
  (CLAUDE_PROJECT_DIR="$PWD" prompt_hook '{"session_id":"c1","hook_event_name":"UserPromptSubmit"}' >/dev/null)  # subshell: sh keeps assignments made before a function call
  check "claude code detected" 'grep -q "\"agent\": \"claude-code\"" "$sd/pulse.json"'
  CUR=T15; echo "T15 nudge off"
  $C config nudge off >/dev/null
  rm -f "$sd/nudge-at"
  n=0; i=0
  while [ $i -lt 4 ]; do
    out="$(prompt_hook '{"session_id":"s15","hook_event_name":"UserPromptSubmit"}')"; [ -n "$out" ] && n=$((n + 1)); i=$((i + 1))
  done
  check "no nudge when off" '[ $n -eq 0 ]'
  check "pulse still written" 'grep -q "\"session\": \"s15\"" "$sd/pulse.json"'
  out="$(printf '{}' | sh .continuity/scripts/hook-precompact.sh)"
  check "precompact silent when off" '[ -z "$out" ]'
fi

# ---------------------------------------------------------------- T16 / T17
CUR=T16; if want T16 || want T17; then echo "T16 workstream identity"
  mkrepo t16
  id="$(ws_new "Original title" --branch main)"
  handoff "$id"
  git mv .continuity/workstreams/original-title.md .continuity/workstreams/renamed.md
  sed 's/^title: .*/title: Totally different/; s/^branch: .*/branch: some-other-branch/' .continuity/workstreams/renamed.md > x && mv x .continuity/workstreams/renamed.md
  out="$($C pickup "$id")"
  check "pickup by id after rename" 'printf "%s" "$out" | grep -q "Totally different ($id)"'
  check "checkpoints still linked" 'printf "%s" "$out" | grep -q "latest checkpoint: .continuity/checkpoints/"'
  check "id format ws_ plus 8" 'printf "%s" "$id" | grep -qE "^ws_[0-9a-z]{8}$"'
  CUR=T17; echo "T17 two workstreams on main"
  a="$(ws_new "Alpha" --branch main)"; b="$(ws_new "Beta" --branch main)"
  handoff "$a" "Alpha next"; handoff "$b" "Beta next"; handoff "$a" "Alpha next 2"
  na="$(grep -l "^workstream: $a" .continuity/checkpoints/*.md | wc -l | tr -d ' ')"
  nb="$(grep -l "^workstream: $b" .continuity/checkpoints/*.md | wc -l | tr -d ' ')"
  check "checkpoints land under their own workstream" '[ "$na" -eq 2 ] && [ "$nb" -eq 1 ]'
  out="$($C pickup)"
  check "pickup lists both" 'printf "%s" "$out" | grep -q "Alpha" && printf "%s" "$out" | grep -q "Beta"'
  check "next_step updated per workstream" 'grep -Eq "^next_step: \"?Alpha next 2\"?$" .continuity/workstreams/alpha.md && grep -Eq "^next_step: \"?Beta next\"?$" .continuity/workstreams/beta.md'
fi

# ---------------------------------------------------------------- T18
CUR=T18; if want T18; then echo "T18 auto snapshot promotion"
  mkrepo t18
  id="$(ws_new "Snap" --branch main)"
  handoff "$id"
  echo wip >> a.txt
  sleep 1
  printf '{"session_id":"gone","hook_event_name":"SessionEnd","reason":"other"}' | sh .continuity/scripts/hook-sessionend.sh
  sd="$(git rev-parse --git-dir)/continuity"
  out="$($C pickup "$id")"
  check "pickup reports session without handoff" 'printf "%s" "$out" | grep -q "ended without a handoff after the checkpoint"'
  handoff "$id"
  check "handoff folds snapshot" 'grep -q "folded 1 session snapshot" "$TMPROOT/last-handoff.out"'
  check "snapshot moved to promoted" '[ -z "$(ls "$sd/auto"/*.md 2>/dev/null)" ] && ls "$sd/auto/promoted"/*gone.md >/dev/null 2>&1'
  cp="$(ls -t .continuity/checkpoints/*.md | head -n 1)"
  check "checkpoint carries the section" 'grep -q "## Sessions that ended without a handoff" "$cp"'
  check "dirty file recorded, not committed" 'grep -q "a.txt" "$cp" && git diff --name-only | grep -qx a.txt'
fi

# ---------------------------------------------------------------- T20
CUR=T20; if want T20; then echo "T20 uninstall"
  mkrepo t20
  mkdir -p .claude
  printf '{\n  "hooks": {"UserPromptSubmit": [{"hooks": [{"type": "command", "command": "echo hi"}]}]}\n}\n' > .claude/settings.json
  printf '# Agents\n\nExisting rule.\n' > AGENTS.md
  git add -A; git commit -qm "pre"
  ah="$(git hash-object AGENTS.md)"
  $C init --yes >/dev/null 2>&1
  $C uninstall --yes >/dev/null 2>&1
  check "continuity hooks removed from claude settings" '! grep -q "continuity/scripts/hook-" .claude/settings.json'
  check "other hook kept" 'grep -q "\"echo hi\"" .claude/settings.json'
  check "codex hooks file removed (was only ours)" '[ ! -f .codex/hooks.json ]'
  check "skill links removed" '[ ! -e .claude/skills/handoff ] && [ ! -e .claude/skills/pickup ]'
  check "AGENTS.md restored exactly" '[ "$(git hash-object AGENTS.md)" = "$ah" ]'
  check ".continuity kept" '[ -d .continuity/scripts ]'
  check "state dir removed" '[ ! -d "$(git rev-parse --git-dir)/continuity" ]'
fi

# ---------------------------------------------------------------- T21 robustness
CUR=T21; if want T21; then echo "T21 hooks fail safe"
  mkdir -p "$TMPROOT/notrepo"; cd "$TMPROOT/notrepo"
  out="$(printf 'garbage' | sh "$SRC/.continuity/scripts/hook-prompt.sh"; echo "rc=$?")"
  check "outside a repo: silent exit 0" '[ "$out" = "rc=0" ]'
  out="$(printf '' | sh "$SRC/.continuity/scripts/hook-sessionend.sh"; echo "rc=$?")"
  check "sessionend outside repo: exit 0" '[ "$out" = "rc=0" ]'
  mkrepo t21
  out="$(printf 'not json at all' | sh .continuity/scripts/hook-prompt.sh; echo "rc=$?")"
  check "malformed payload: exit 0" 'printf "%s" "$out" | grep -q "rc=0$"'
  rm -f .continuity/config.yml
  out="$(printf '{}' | sh .continuity/scripts/hook-prompt.sh; echo "rc=$?")"
  check "uninitialized repo: silent" '[ "$out" = "rc=0" ]'
fi

# ---------------------------------------------------------------- T22 / T23
CUR=T22; if want T22 || want T23; then echo "T22 no exit snapshot when nothing changed since handoff"
  mkrepo t22
  id="$(ws_new "Quiet" --branch main)"
  echo wip >> a.txt
  handoff "$id"
  sd="$(git rev-parse --git-dir)/continuity"
  printf '{"session_id":"after-handoff"}' | sh .continuity/scripts/hook-sessionend.sh
  check "no snapshot right after a handoff" '[ -z "$(ls "$sd/auto"/*.md 2>/dev/null)" ]'
  echo more >> a.txt
  printf '{"session_id":"changed"}' | sh .continuity/scripts/hook-sessionend.sh
  check "snapshot once the tree changes" 'ls "$sd/auto"/*-changed.md >/dev/null 2>&1'
  CUR=T23; echo "T23 recorded wip does not re-trigger the nudge"
  git checkout -q -- a.txt; echo wip >> a.txt
  handoff "$id"
  rm -f "$sd/nudge-at"
  n=0; i=0
  while [ $i -lt 5 ]; do out="$(prompt_hook '{"session_id":"s23"}')"; [ -n "$out" ] && n=$((n + 1)); i=$((i + 1)); done
  check "no nudge for wip the handoff recorded" '[ $n -eq 0 ]'
  echo newer >> a.txt
  n=0; i=0
  while [ $i -lt 3 ]; do out="$(prompt_hook '{"session_id":"s23"}')"; [ -n "$out" ] && n=$((n + 1)); i=$((i + 1)); done
  check "nudge once the tree moves on" '[ $n -eq 1 ]'
fi

# ---------------------------------------------------------------- T24
CUR=T24; if want T24; then echo "T24 payloads shaped like the Codex and Claude Code schemas"
  mkrepo t24
  echo wip >> a.txt
  sd="$(git rev-parse --git-dir)/continuity"
  cx_ups='{"session_id":"thr_1","transcript_path":"/home/u/.codex/sessions/2026/10/27/rollout-x.jsonl","cwd":"'"$PWD"'","hook_event_name":"UserPromptSubmit","model":"gpt-5","permission_mode":"default","prompt":"hi","turn_id":"t1"}'
  cx_end='{"session_id":"thr_1","transcript_path":"/home/u/.codex/sessions/2026/10/27/rollout-x.jsonl","cwd":"'"$PWD"'","hook_event_name":"SessionEnd","reason":"other"}'
  cx_pc='{"session_id":"thr_1","transcript_path":null,"cwd":"'"$PWD"'","hook_event_name":"PreCompact","model":"gpt-5","trigger":"auto","turn_id":"t2"}'
  out="$(printf '%s' "$cx_ups" | sh .continuity/scripts/hook-prompt.sh; echo "rc=$?")"
  check "codex UserPromptSubmit exits 0" 'printf "%s" "$out" | grep -q "rc=0$"'
  check "codex agent recorded" 'grep -q "\"agent\": \"codex\"" "$sd/pulse.json" && grep -q "thr_1" "$sd/pulse.json"'
  out="$(printf '%s' "$cx_pc" | sh .continuity/scripts/hook-precompact.sh)"
  check "codex PreCompact output is schema-valid JSON" 'printf "%s" "$out" | python3 -c "import json,sys; d=json.load(sys.stdin); assert set(d) <= {\"continue\",\"stopReason\",\"suppressOutput\",\"systemMessage\"}"'
  printf '%s' "$cx_end" | sh .continuity/scripts/hook-sessionend.sh
  check "codex SessionEnd snapshot tagged codex" 'grep -q "^agent: codex" "$sd/auto"/*thr_1.md'
  cl_end='{"session_id":"abc-123","transcript_path":"/home/u/.claude/projects/x/abc-123.jsonl","cwd":"'"$PWD"'","hook_event_name":"SessionEnd","reason":"prompt_input_exit"}'
  printf '%s' "$cl_end" | sh .continuity/scripts/hook-sessionend.sh
  check "claude SessionEnd snapshot tagged claude-code" 'grep -q "^agent: claude-code" "$sd/auto"/*abc-123.md && grep -q "prompt_input_exit" "$sd/auto"/*abc-123.md'
fi

# ---------------------------------------------------------------- T25
CUR=T25; if want T25; then echo "T25 phases from a table, and section fallback"
  mkrepo t25
  mkdir -p docs
  printf '# Plan\n\n## Proposed sequence\n\n| Phase | Work |\n| --- | --- |\n| 1. Build it | x |\n| 2. Ship it | y |\n| 3. Watch it | z |\n' > docs/roadmap.md
  printf '# Cutover\n\n## A. The day before\n\ntext\n\n## B. Switch\n\ntext\n' > docs/cutover.md
  out="$($C outline docs/roadmap.md)"
  check "table rows become phases" 'printf "%s" "$out" | grep -q "\"title\": \"2. Ship it\""'
  out="$($C outline docs/cutover.md)"
  check "sections become phases when nothing else does" 'printf "%s" "$out" | grep -q "\"title\": \"B. Switch\""'
fi

# ---------------------------------------------------------------- T26
CUR=T26; if want T26; then echo "T26 project overview and plan map"
  mkrepo t26
  mkdir -p docs
  printf '# Roadmap\n\n| Phase | Work |\n| --- | --- |\n| 1. Build | x |\n| 2. Ship | y |\n' > docs/roadmap.md
  printf '# Done thing\n\n## Phase 1: Do\n- [x] did it\n' > docs/done.md
  printf '# Open thing\n\n## Phase 1: Do\n- [ ] do it\n' > docs/open.md
  printf '# Shelved thing\n\n## Phase 1: Maybe\n- [ ] later\n' > docs/shelf.md
  printf '# Status log\n' > docs/status.md
  git add docs; GIT_COMMITTER_DATE="2026-09-01T12:00:00Z" git commit -qm "docs"
  cat > .continuity/onboarding/proposal.json <<'JSON'
{"version": 1,
 "project": {"title": "Demo", "roadmap": "rm", "goal": "Ship the demo.", "context": [{"doc": "docs/status.md", "why": "status log"}]},
 "plans": [
  {"doc": "docs/roadmap.md", "slug": "rm", "title": "Roadmap", "kind": "roadmap", "status": "active", "phase_status": {"p1": "done", "p2": "active"}, "decision": "approved"},
  {"doc": "docs/done.md", "slug": "done", "title": "Done thing", "kind": "feature", "status": "done", "parent": "rm", "implements": ["rm/p1"], "decision": "approved"},
  {"doc": "docs/open.md", "slug": "open", "title": "Open thing", "kind": "feature", "status": "active", "parent": "rm", "implements": ["rm/p2"], "decision": "approved",
   "workstream": {"title": "Open work", "next_step": "Do it", "status": "active"}},
  {"doc": "docs/shelf.md", "slug": "shelf", "title": "Shelved thing", "kind": "fix", "status": "paused", "decision": "approved"},
  {"doc": "docs/status.md", "slug": "status", "title": "Status", "kind": "status", "status": "active", "decision": "rejected"}
 ]}
JSON
  $C onboard apply .continuity/onboarding/proposal.json --yes >/dev/null 2>&1
  out="$($C map)"
  check "completed plan collapsed by default" 'printf "%s" "$out" | grep -q "1 completed plan(s), collapsed" && ! printf "%s" "$out" | grep -q "Done thing"'
  check "open plan listed under its phase" 'printf "%s" "$out" | grep -A1 "2. Ship \[active\]" | grep -q "Open thing"'
  check "paused plan stays visible" 'printf "%s" "$out" | grep -q "Shelved thing \[fix, paused\]"'
  out="$($C map --all)"
  check "map --all expands completed" 'printf "%s" "$out" | grep -q "Done thing \[feature, done\]"'
  out="$($C pickup)"
  check "overview shows goal" 'printf "%s" "$out" | grep -q "Ship the demo."'
  check "overview lists context documents" 'printf "%s" "$out" | grep -q "docs/status.md: status log"'
  check "overview lists the workstream" 'printf "%s" "$out" | grep -q "Open work \[active\]"'
  check "workstream dated by its document history" 'grep -q "^last_activity_at: \"\{0,1\}2026-09-01" .continuity/workstreams/open-work.md'
fi

# ---------------------------------------------------------------- T27
CUR=T27; if want T27; then echo "T27 instruction block follows the repository's main instruction file"
  mkrepo t27
  printf 'Read CLAUDE.md first; it is the single source of truth.\n' > AGENTS.md
  printf '# Rules\n\nLong project rules live here.\nMore rules.\nEven more rules.\n' > CLAUDE.md
  git add -A; git commit -qm "claude-first"
  $C init --yes >/dev/null 2>&1
  check "full block in the longer file (CLAUDE.md)" 'grep -q "## Continuity checkpoints" CLAUDE.md'
  check "pointer in the short file names CLAUDE.md" '! grep -q "## Continuity checkpoints" AGENTS.md && grep -q "section of CLAUDE.md" AGENTS.md'
  $C init --yes >/dev/null 2>&1
  check "re-running keeps the same placement" 'grep -q "## Continuity checkpoints" CLAUDE.md && grep -q "section of CLAUDE.md" AGENTS.md && [ "$(grep -c "continuity:start" CLAUDE.md)" -eq 1 ]'
  mkrepo t27b
  printf '# Only Claude\n' > CLAUDE.md
  git add -A; git commit -qm "claude-only"
  $C init --yes >/dev/null 2>&1
  check "only CLAUDE.md: it gets the full block, no AGENTS.md created" 'grep -q "## Continuity checkpoints" CLAUDE.md && [ ! -f AGENTS.md ]'
fi

# ---------------------------------------------------------------- T28
CUR=T28; if want T28; then echo "T28 dashboard: send checkpoints before they are pushed"
  cat > "$TMPROOT/fake_dashboard.py" <<'EOF'
import json, os, sys, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
d = sys.argv[1]
TOKEN = "cmt_" + "t" * 40
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def reply(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    def mode(self):
        try: return open(os.path.join(d, "mode")).read().strip()
        except OSError: return "ok"
    def do_GET(self):
        if self.headers.get("Authorization") != "Bearer " + TOKEN: return self.reply(401, {"error": "bad key"})
        self.reply(200, {"ok": True, "machine": "Test Mac", "repos": ["o/app"]})
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", 0))).decode()
        with open(os.path.join(d, "requests.log"), "a") as f:
            f.write(json.dumps({"auth": self.headers.get("Authorization"), "body": json.loads(body)}) + "\n")
        m = self.mode()
        if m == "hang": time.sleep(20)
        if m in ("401", "404", "500"): return self.reply(int(m), {"error": "mode " + m})
        seen = os.path.join(d, "seen")
        ids = open(seen).read().split() if os.path.exists(seen) else []
        cid = json.loads(body)["checkpoint"]["text"].split("\nid: ")[1].split("\n")[0].strip('"')
        if cid in ids: return self.reply(200, {"status": "duplicate"})
        open(seen, "a").write(cid + "\n")
        self.reply(200, {"status": "stored"})
s = ThreadingHTTPServer(("127.0.0.1", 0), H)
open(os.path.join(d, "port"), "w").write(str(s.server_address[1]))
s.serve_forever()
EOF
  FD="$TMPROOT/fake"; mkdir -p "$FD"; rm -f "$FD"/*
  python3 "$TMPROOT/fake_dashboard.py" "$FD" & FAKE_PID=$!
  i=0; while [ ! -s "$FD/port" ] && [ $i -lt 50 ]; do sleep 0.1; i=$((i + 1)); done
  URL="http://127.0.0.1:$(cat "$FD/port")"; TOKEN="cmt_tttttttttttttttttttttttttttttttttttttttt"
  reqs() { [ -f "$FD/requests.log" ] && wc -l < "$FD/requests.log" | tr -d ' ' || echo 0; }
  waiting() { ls "$CONTINUITY_HOME/outbox/"*.json 2>/dev/null | wc -l | tr -d ' '; }
  export CONTINUITY_HOME="$TMPROOT/home28"; rm -rf "$CONTINUITY_HOME"

  mkrepo t28; git remote add origin git@github.com:o/app.git
  W=$(ws_new "Dash" --branch main)
  echo "password = hunter2-not-a-real-one-but-long-enough" > app-secret.txt   # uncommitted source file
  handoff "$W"
  check "not connected: handoff commits, nothing sent, no outbox" '[ "$(reqs)" = 0 ] && [ ! -d "$CONTINUITY_HOME/outbox" ] && grep -q "Handoff committed" "$TMPROOT/last-handoff.out"'

  check "connect refuses a malformed key" '! $C dashboard connect "$URL" nope >/dev/null 2>&1'
  check "connect refuses a key the dashboard rejects" '! $C dashboard connect "$URL" cmt_wrongwrongwrongwrongwrong >/dev/null 2>&1 && [ ! -f "$CONTINUITY_HOME/dashboard.json" ]'
  $C dashboard connect "$URL" "$TOKEN" > "$TMPROOT/t28-connect.out" 2>&1
  check "connect saves the key readable only by you" '[ "$(stat -f %Lp "$CONTINUITY_HOME/dashboard.json" 2>/dev/null || stat -c %a "$CONTINUITY_HOME/dashboard.json")" = 600 ] && grep -q "Test Mac" "$TMPROOT/t28-connect.out"'
  check "connect says whether this project is connected" 'grep -q "o/app) is connected" "$TMPROOT/t28-connect.out"'

  handoff "$W" "Second step."
  check "handoff sends the checkpoint at once" '[ "$(reqs)" = 1 ] && [ "$(waiting)" = 0 ] && grep -q "Dashboard: sent 1" "$TMPROOT/last-handoff.out"'
  check "sent with the key, repo, branch and the checkpoint's own commit" 'python3 - "$FD/requests.log" "$(git rev-parse HEAD)" "$TOKEN" <<EOF2
import json, sys
r = json.loads(open(sys.argv[1]).read().splitlines()[-1]); b = r["body"]
assert r["auth"] == "Bearer " + sys.argv[3]
assert b["repo"] == "o/app" and b["branch"] == "main" and b["checkpoint_commit"] == sys.argv[2]
assert b["checkpoint"]["path"].startswith(".continuity/checkpoints/") and b["workstream"]["path"].startswith(".continuity/workstreams/")
EOF2'
  check "only the checkpoint and workstream files are sent, no source content" 'python3 - "$FD/requests.log" <<EOF2
import json, sys
b = json.loads(open(sys.argv[1]).read().splitlines()[-1])["body"]
assert set(b) == {"version", "repo", "branch", "checkpoint_commit", "checkpoint", "workstream"}, set(b)
assert "hunter2" not in json.dumps(b)
EOF2'
  check "the uncommitted file was left alone (not committed, not sent)" '[ -n "$(git status --porcelain app-secret.txt)" ]'

  echo 500 > "$FD/mode"
  handoff "$W" "Offline step."; rc=$?
  check "dashboard down: handoff still succeeds and keeps the checkpoint" '[ $rc -eq 0 ] && [ "$(waiting)" = 1 ] && grep -q "go with the next handoff or pickup" "$TMPROOT/last-handoff.out"'
  $C dashboard send >/dev/null 2>&1; $C dashboard send >/dev/null 2>&1
  check "re-queuing the same checkpoint never makes a second copy" '[ "$(waiting)" = 1 ]'
  echo ok > "$FD/mode"
  $C pickup > "$TMPROOT/t28-pickup.out" 2>&1
  check "the next pickup sends what was waiting" '[ "$(waiting)" = 0 ] && grep -q "Dashboard: sent 1" "$TMPROOT/t28-pickup.out"'
  $C dashboard send > "$TMPROOT/t28-dup.out" 2>&1
  check "sending the same checkpoint again is harmless (the dashboard reports a duplicate)" '[ "$(waiting)" = 0 ] && [ "$(sort -u "$FD/seen" | wc -l | tr -d " ")" = "$(wc -l < "$FD/seen" | tr -d " ")" ]'

  echo 401 > "$FD/mode"
  handoff "$W" "Revoked step."
  check "a revoked key keeps the checkpoint and says how to fix it" '[ "$(waiting)" = 1 ] && grep -q "key was refused" "$TMPROOT/last-handoff.out"'
  echo 404 > "$FD/mode"
  $C dashboard flush > "$TMPROOT/t28-404.out" 2>&1
  check "a project the dashboard does not know is reported and dropped" '[ "$(waiting)" = 0 ] && grep -q "not sent" "$TMPROOT/t28-404.out"'

  echo ok > "$FD/mode"; n=$(reqs)
  $C config dashboard off >/dev/null
  handoff "$W" "Quiet step."
  check "config dashboard off: nothing sent for this project" '[ "$(reqs)" = "$n" ] && grep -q "sending is off" "$TMPROOT/last-handoff.out"'
  $C config dashboard auto >/dev/null

  git remote remove origin
  handoff "$W" "No remote step."
  check "no GitHub remote: handoff succeeds and says why nothing was sent" '[ "$(reqs)" = "$n" ] && grep -q "no GitHub origin remote" "$TMPROOT/last-handoff.out"'
  git remote add origin https://github.com/o/app.git

  echo hang > "$FD/mode"
  t0=$(now_f); handoff "$W" "Slow step."; rc=$?; t1=$(now_f)
  check "a hanging dashboard delays the handoff by seconds, not minutes" '[ $rc -eq 0 ] && python3 -c "import sys; sys.exit(0 if $t1 - $t0 < 12 else 1)" && [ "$(waiting)" = 1 ]'
  echo ok > "$FD/mode"
  $C dashboard disconnect >/dev/null
  check "disconnect removes the key; handoffs stop sending" '[ ! -f "$CONTINUITY_HOME/dashboard.json" ]'

  kill $FAKE_PID 2>/dev/null; wait $FAKE_PID 2>/dev/null
  CONTINUITY_HOME="$TMPROOT/home"; export CONTINUITY_HOME
fi

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || { echo "failed tests:$FAILED"; exit 1; }
exit 0
