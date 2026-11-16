#!/usr/bin/env python3
"""Continuity Stage 0 core. Standard library only; Python 3.8+.

Subcommands (normally reached through the `.continuity/continuity` dispatcher):
  outline FILE                      parse a plan document into JSON
  sync-plans [--check]              refresh plan stubs whose original changed
  workstream-new TITLE [--branch B] [--plans a,b] [--next TEXT]
  workstream-list                   unfinished workstreams
  checkpoint-new WS [--agent A] [--kind K]
  checkpoint-commit FILE [--summary S]   the /handoff commit step
  pickup [WS] [--facts]             verification report for resuming
  validate FILE                     validate a checkpoint without committing
  secret-scan FILE...               exit 4 on a key-shaped string
  config KEY VALUE                  set a key in .continuity/config.yml
  dashboard connect URL TOKEN|status|flush|send [FILE]|disconnect
  install [--yes] [--skills-mode link|copy]
  uninstall [--yes] [--purge] [--hooks-only]
  onboard-inventory                 write .continuity/onboarding/inventory.json
  onboard-apply PROPOSAL.json [--yes]
"""
import datetime as _dt
import difflib
import hashlib
import json
import os
import random
import re
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

CONT = ".continuity"
CROCKFORD = "0123456789abcdefghjkmnpqrstvwxyz"
DERIVED_MARK = "<!-- derived from {path}; do not edit below this line -->"
DERIVED_RE = re.compile(r"^<!-- derived from (.+?); do not edit below this line -->\s*$")
SECTIONS = [
    "Objective", "Completed since last checkpoint", "Evidence",
    "Decisions and rejected alternatives", "Open questions and blockers",
    "What to check on return", "Next step", "Resume prompt",
]
KINDS = {"agent", "auto", "manual", "onboarding"}
RUNGS = ["implemented", "tested", "deployed", "verified"]
WS_OPEN = {"active", "blocked", "waiting", "paused"}
MANAGED_MARK = ".continuity-managed"
BLOCK_START = "<!-- continuity:start -->"
BLOCK_END = "<!-- continuity:end -->"


# ---------------------------------------------------------------- utilities

def die(msg, code=1):
    sys.stderr.write("continuity: %s\n" % msg)
    sys.exit(code)


def run(args, check=False, timeout=None, cwd=None):
    try:
        p = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           universal_newlines=True, timeout=timeout, cwd=cwd)
    except (subprocess.TimeoutExpired, FileNotFoundError, OSError):
        if check:
            die("command failed: %s" % " ".join(args))
        return None
    if check and p.returncode != 0:
        die("command failed: %s\n%s" % (" ".join(args), p.stderr.strip()))
    return p


def git(*args, **kw):
    """stdout with surrounding newlines removed (leading spaces kept: porcelain
    status lines start with a space), or None when git failed."""
    p = run(["git"] + list(args), **kw)
    return p.stdout.strip("\n") if p and p.returncode == 0 else None


def git_ok(*args):
    p = run(["git"] + list(args))
    return p is not None and p.returncode == 0


def repo_root():
    r = git("rev-parse", "--show-toplevel")
    if not r:
        die("not inside a git work tree")
    return r


def state_dir():
    d = git("rev-parse", "--git-dir")
    if d is None:
        die("not inside a git work tree")
    if not os.path.isabs(d):
        d = os.path.join(os.getcwd(), d)
    d = os.path.join(d, "continuity")
    os.makedirs(d, exist_ok=True)
    return d


def now_iso():
    return _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(s):
    if not s:
        return None
    s = str(s).strip()
    for fmt in ("%Y-%m-%dT%H:%M:%SZ", "%Y-%m-%dT%H:%M:%S%z", "%Y-%m-%d"):
        try:
            d = _dt.datetime.strptime(s, fmt)
            if d.tzinfo is None:
                d = d.replace(tzinfo=_dt.timezone.utc)
            return d
        except ValueError:
            continue
    try:
        d = _dt.datetime.fromisoformat(s.replace("Z", "+00:00"))
        return d if d.tzinfo else d.replace(tzinfo=_dt.timezone.utc)
    except ValueError:
        return None


def rand_id(prefix, n=8):
    return prefix + "".join(random.SystemRandom().choice(CROCKFORD) for _ in range(n))


def ulid():
    t = int(time.time() * 1000)
    chars = []
    for _ in range(10):
        chars.append(CROCKFORD[t % 32])
        t //= 32
    s = "".join(reversed(chars))
    r = random.SystemRandom()
    s += "".join(r.choice(CROCKFORD) for _ in range(16))
    return s.upper()


def slugify(text, maxlen=48):
    s = re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")
    return (s[:maxlen].rstrip("-")) or "untitled"


def blob_hash(path):
    """Same value as `git hash-object PATH` for files without filters."""
    with open(path, "rb") as f:
        data = f.read()
    h = hashlib.sha1(b"blob %d\0" % len(data))
    h.update(data)
    return h.hexdigest()


def text_hash(s):
    return hashlib.sha1(s.encode("utf-8")).hexdigest()[:16]


def read(path):
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        return f.read()


def write(path, content):
    d = os.path.dirname(path)
    if d:
        os.makedirs(d, exist_ok=True)
    tmp = path + ".tmp.%d" % os.getpid()
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(content)
    os.replace(tmp, path)


def confirm(question, assume_yes):
    if assume_yes:
        return True
    if not sys.stdin.isatty():
        print("  (non-interactive; not applied. Re-run with --yes to apply.)")
        return False
    ans = input("%s [y/N] " % question).strip().lower()
    return ans in ("y", "yes")


def show_diff(path, old, new):
    lines = list(difflib.unified_diff(old.splitlines(True), new.splitlines(True),
                                      fromfile="a/" + path, tofile="b/" + path))
    if lines:
        sys.stdout.write("".join(lines))
        if not lines[-1].endswith("\n"):
            sys.stdout.write("\n")
    return bool(lines)


# ------------------------------------------------- minimal YAML front matter

_PLAIN_SAFE = re.compile(r"^[A-Za-z0-9_./@+~-][A-Za-z0-9_./@+~ ()-]*$")
_SPECIAL = {"true", "false", "null", "yes", "no", "~", ""}


def _scalar(tok):
    t = tok.strip()
    if t == "":
        return ""
    if t[0] == '"':
        try:
            return json.loads(t)
        except ValueError:
            return t.strip('"')
    if t[0] == "'" and t.endswith("'") and len(t) >= 2:
        return t[1:-1].replace("''", "'")
    low = t.lower()
    if low in ("true", "yes"):
        return True
    if low in ("false", "no"):
        return False
    if low in ("null", "~"):
        return None
    if re.match(r"^-?\d+$", t):
        return int(t)
    return t


def _strip_comment(v):
    out, q = [], None
    for i, ch in enumerate(v):
        if q:
            out.append(ch)
            if ch == q and (q == "'" or v[i - 1] != "\\"):
                q = None
            continue
        if ch in "\"'" and (i == 0 or v[i - 1] in " [{,:"):
            q = ch
        elif ch == "#" and (i == 0 or v[i - 1] == " "):
            break
        out.append(ch)
    return "".join(out).rstrip()


def _flow(s, i):
    """Parse a flow collection or scalar starting at s[i]. Returns (value, next_i)."""
    n = len(s)
    while i < n and s[i] == " ":
        i += 1
    if i < n and s[i] == "[":
        i += 1
        items = []
        while True:
            while i < n and s[i] in " ,":
                i += 1
            if i >= n:
                raise ValueError("unterminated [")
            if s[i] == "]":
                return items, i + 1
            v, i = _flow(s, i)
            items.append(v)
    if i < n and s[i] == "{":
        i += 1
        d = {}
        while True:
            while i < n and s[i] in " ,":
                i += 1
            if i >= n:
                raise ValueError("unterminated {")
            if s[i] == "}":
                return d, i + 1
            j = s.find(":", i)
            if j < 0:
                raise ValueError("expected key: in {}")
            key = _scalar(s[i:j])
            v, i = _flow(s, j + 1)
            d[str(key)] = v
    # scalar
    if i < n and s[i] in "\"'":
        q = s[i]
        j = i + 1
        while j < n:
            if s[j] == q and (q == "'" or s[j - 1] != "\\"):
                break
            j += 1
        return _scalar(s[i:j + 1]), j + 1
    j = i
    while j < n and s[j] not in ",]}":
        j += 1
    return _scalar(s[i:j]), j


def parse_value(v):
    v = _strip_comment(v.strip())
    if v[:1] in "[{":
        val, _ = _flow(v, 0)
        return val
    return _scalar(v)


def split_front_matter(text):
    """Return (fm_text or None, body)."""
    if not text.startswith("---"):
        return None, text
    lines = text.split("\n")
    if lines[0].strip() != "---":
        return None, text
    for k in range(1, len(lines)):
        if lines[k].strip() == "---":
            return "\n".join(lines[1:k]), "\n".join(lines[k + 1:])
    return None, text


def parse_fm(text):
    fm_text, body = split_front_matter(text)
    if fm_text is None:
        return {}, body
    data, key, block = {}, None, None
    for raw in fm_text.split("\n"):
        if not raw.strip() or raw.lstrip().startswith("#"):
            continue
        indented = raw[:1] in (" ", "\t")
        if indented and key is not None:
            s = raw.strip()
            if s.startswith("- "):
                if block is None:
                    block = []
                if not isinstance(block, list):
                    raise ValueError("mixed block under %s" % key)
                block.append(parse_value(s[2:]))
            else:
                m = re.match(r"^([A-Za-z0-9_-]+):\s*(.*)$", s)
                if not m:
                    raise ValueError("cannot parse line: %r" % raw)
                if block is None:
                    block = {}
                if not isinstance(block, dict):
                    raise ValueError("mixed block under %s" % key)
                block[m.group(1)] = parse_value(m.group(2)) if m.group(2).strip() else ""
            data[key] = block
            continue
        m = re.match(r"^([A-Za-z0-9_-]+):\s*(.*)$", raw)
        if not m:
            raise ValueError("cannot parse front matter line: %r" % raw)
        key, val = m.group(1), m.group(2)
        if val.strip() == "":
            block = None
            data[key] = None
        else:
            block = None
            data[key] = parse_value(val)
            key = key  # further indented lines would be an error but are tolerated
    return data, body


def dump_scalar(v):
    if v is None:
        return "null"
    if v is True:
        return "true"
    if v is False:
        return "false"
    if isinstance(v, int):
        return str(v)
    s = str(v)
    if (_PLAIN_SAFE.match(s) and s.lower() not in _SPECIAL and not re.match(r"^-?\d+$", s)
            and not s.endswith(" ")):
        return s
    return json.dumps(s, ensure_ascii=False)


def dump_value(v):
    if isinstance(v, list):
        return "[" + ", ".join(dump_value(x) for x in v) + "]"
    if isinstance(v, dict):
        return "{" + ", ".join("%s: %s" % (k, dump_value(x)) for k, x in v.items()) + "}"
    return dump_scalar(v)


def dump_fm(data):
    return "---\n" + "".join("%s: %s\n" % (k, dump_value(v)) for k, v in data.items()) + "---\n"


def load_doc(path):
    text = read(path)
    try:
        fm, body = parse_fm(text)
    except ValueError as e:
        die("%s: invalid front matter: %s" % (path, e))
    return fm, body


# ---------------------------------------------------------- plan outlines

HEADING_RE = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")
ITEM_RE = re.compile(r"^(\s*)[-*+]\s+\[([ xX])\]\s+(.*)$")
PHASE_WORD = re.compile(r"\b(phase|stage|step|milestone|part|sprint|increment|wave|week)\b\s*[0-9ivx]",
                        re.I)
ID_SUFFIX = re.compile(r"\s+\{([a-z]+_?[a-z0-9]+)\}\s*")
DONE_AT_RE = re.compile(r"\s+done_at:\s*(implemented|tested|deployed|verified)\b")
REF_RE = re.compile(r"\(((?:PR|pr) #\d+|#\d+|commit [0-9a-f]{7,40}|https?://\S+)\)")


def norm(text):
    t = re.sub(r"[`*_~]", "", text)
    t = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", t)
    return re.sub(r"\s+", " ", t).strip().lower()


def parse_outline(text, stub=False):
    """Phases and checklist items from markdown. Stub mode reads {id} suffixes,
    done_at annotations and the removed: list."""
    phases, removed = [], []
    lines = text.split("\n")
    in_fence = False
    headings = []
    for idx, line in enumerate(lines):
        if line.strip().startswith(("```", "~~~")):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        m = HEADING_RE.match(line)
        if m and len(m.group(1)) >= 1:
            headings.append((idx, len(m.group(1)), m.group(2)))
    use_phase_words = (not stub) and any(PHASE_WORD.search(h[2]) for h in headings)
    current = None
    in_fence = False
    in_removed = False

    def new_phase(title, level):
        pid = None
        if stub:
            mm = ID_SUFFIX.search(" " + title + " ")
            if mm:
                pid = mm.group(1)
                title = ID_SUFFIX.sub(" ", " " + title + " ").strip()
        ph = {"title": title.strip(), "id": pid, "level": level, "items": []}
        phases.append(ph)
        return ph

    for idx, line in enumerate(lines):
        st = line.strip()
        if st.startswith(("```", "~~~")):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        if stub and st == "removed:":
            in_removed = True
            continue
        if stub and in_removed:
            mm = re.match(r'^-\s+([a-z]+_[a-z0-9]+):\s+(".*")\s*(?:\(last seen ([0-9-]+)\))?\s*$', st)
            if mm:
                try:
                    txt = json.loads(mm.group(2))
                except ValueError:
                    txt = mm.group(2).strip('"')
                removed.append({"id": mm.group(1), "text": txt, "last_seen": mm.group(3) or ""})
            continue
        m = HEADING_RE.match(line)
        if m:
            title = m.group(2)
            if stub or not use_phase_words or PHASE_WORD.search(title):
                current = new_phase(title, len(m.group(1)))
            continue
        mi = ITEM_RE.match(line)
        if mi:
            body = mi.group(3)
            iid, done_at = None, None
            if stub:
                mm = ID_SUFFIX.search(body + " ")
                if mm:
                    iid = mm.group(1)
                    body = ID_SUFFIX.sub(" ", body + " ")
                md = DONE_AT_RE.search(body)
                if md:
                    done_at = md.group(1)
                    body = DONE_AT_RE.sub("", body)
            body = body.strip()
            if current is None:
                current = new_phase("(unphased)", 0)
                current["id"] = current["id"] or ("p0" if not stub else current["id"])
            current["items"].append({
                "text": body, "checked": mi.group(2) in "xX", "id": iid,
                "done_at": done_at, "refs": REF_RE.findall(body),
                "depth": len(mi.group(1).replace("\t", "    ")) // 2,
            })
    if not stub:
        phases = [p for p in phases if p["items"] or PHASE_WORD.search(p["title"]) or not use_phase_words]
        if not use_phase_words:
            phases = [p for p in phases if p["items"]]
        if not phases:
            # No phase headings and no checklists: try a phase table, then plain sections.
            phases = table_phases(lines) or section_phases(headings)
    return phases, removed


TABLE_PHASE_RE = re.compile(r"^\s*\|\s*(?:\*\*)?((?:phase|stage|step)\s*\d+[a-z]?\b[^|]*|\d+[a-z]?[.:)]\s+[^|]+?)(?:\*\*)?\s*\|", re.I)


def table_phases(lines):
    """Rows like `| 1. Finish content readiness | ... |` or `| Phase 2 | ... |`."""
    out, in_fence = [], False
    for line in lines:
        if line.strip().startswith(("```", "~~~")):
            in_fence = not in_fence
            continue
        m = None if in_fence else TABLE_PHASE_RE.match(line)
        if m:
            out.append({"title": m.group(1).strip(), "id": None, "level": 0, "items": []})
    return out if len(out) >= 2 else []


def section_phases(headings):
    """Last resort: the document's second-level sections, when there are 2 to 30."""
    h2 = [h for h in headings if h[1] == 2]
    if 2 <= len(h2) <= 30:
        return [{"title": h[2].strip(), "id": None, "level": 2, "items": []} for h in h2]
    return []


def _sim(a, b):
    return difflib.SequenceMatcher(None, norm(a), norm(b)).ratio()


def assign_ids(new_phases, old_phases, old_removed):
    """Carry ids from the previous outline. Returns a list of change strings."""
    changes = []
    used_p = set()
    nums = [int(p["id"][1:]) for p in old_phases if p.get("id") and re.match(r"^p\d+$", p["id"])]
    next_p = max(nums + [0]) + 1
    for ph in new_phases:
        match = None
        for op in old_phases:
            if op.get("id") and op["id"] not in used_p and norm(op["title"]) == norm(ph["title"]):
                match = op
                break
        if not match:
            best, score = None, 0.0
            for op in old_phases:
                if op.get("id") and op["id"] not in used_p:
                    s = _sim(op["title"], ph["title"])
                    if s > score:
                        best, score = op, s
            if best and score >= 0.6:
                match = best
                changes.append('phase renamed: "%s" -> "%s"' % (best["title"], ph["title"]))
        if match:
            ph["id"] = match["id"]
            ph["_old"] = match
            used_p.add(match["id"])
        else:
            if ph["title"] == "(unphased)" and "p0" not in used_p:
                ph["id"] = "p0"
            else:
                ph["id"] = "p%d" % next_p
                next_p += 1
            used_p.add(ph["id"])
            if old_phases:
                changes.append('phase added: "%s"' % ph["title"])
    for op in old_phases:
        if op.get("id") and op["id"] not in used_p:
            changes.append('phase removed: "%s"' % op["title"])

    old_items = [(op, it) for op in old_phases for it in op["items"] if it.get("id")]
    used_i = set()

    def take(pred_pool, test):
        for op, it in pred_pool:
            if it["id"] not in used_i and test(it):
                return it
        return None

    for ph in new_phases:
        same = [(op, it) for op, it in old_items if ph.get("_old") is op]
        for it in ph["items"]:
            m = take(same, lambda o: norm(o["text"]) == norm(it["text"]))
            renamed = False
            if not m:
                cands = [(o, _sim(o["text"], it["text"])) for _, o in same if o["id"] not in used_i]
                cands.sort(key=lambda x: -x[1])
                if cands and cands[0][1] >= 0.6:
                    m, renamed = cands[0][0], True
            if not m:
                m = take(old_items, lambda o: norm(o["text"]) == norm(it["text"]))
            if not m:
                cands = [(o, _sim(o["text"], it["text"])) for _, o in old_items if o["id"] not in used_i]
                cands.sort(key=lambda x: -x[1])
                if cands and cands[0][1] >= 0.6:
                    m, renamed = cands[0][0], True
            revived = None
            if not m:
                for r in old_removed:
                    if norm(r["text"]) == norm(it["text"]):
                        revived = r
                        break
            if m:
                it["id"] = m["id"]
                it["done_at"] = it.get("done_at") or m.get("done_at")
                used_i.add(m["id"])
                if renamed:
                    changes.append('item reworded: %s "%s" -> "%s"' % (m["id"], m["text"], it["text"]))
                if bool(m.get("checked")) != bool(it["checked"]):
                    changes.append("item %s: %s" % (m["id"], "checked" if it["checked"] else "unchecked"))
            elif revived:
                it["id"] = revived["id"]
                used_i.add(revived["id"])
                old_removed.remove(revived)
                changes.append('item restored: %s "%s"' % (it["id"], it["text"]))
            else:
                it["id"] = rand_id("it_", 6)
                used_i.add(it["id"])
                if old_phases:
                    changes.append('item added: %s "%s"' % (it["id"], it["text"]))
    today = _dt.date.today().isoformat()
    removed = list(old_removed)
    for op, it in old_items:
        if it["id"] not in used_i:
            removed.append({"id": it["id"], "text": it["text"], "last_seen": today})
            changes.append('item removed: %s "%s"' % (it["id"], it["text"]))
    for ph in new_phases:
        ph.pop("_old", None)
    return changes, removed


def render_outline(phases, removed):
    out = []
    for ph in phases:
        out.append("## %s  {%s}" % (ph["title"], ph["id"]))
        for it in ph["items"]:
            line = "%s- [%s] %s  {%s}" % ("  " * it.get("depth", 0), "x" if it["checked"] else " ",
                                          it["text"], it["id"])
            if it.get("done_at"):
                line += "  done_at: %s" % it["done_at"]
            out.append(line)
        out.append("")
    if removed:
        out.append("removed:")
        for r in removed:
            out.append('- %s: %s (last seen %s)' % (r["id"], json.dumps(r["text"], ensure_ascii=False),
                                                     r.get("last_seen") or "unknown"))
        out.append("")
    return "\n".join(out).rstrip("\n") + "\n"


def phase_counts(phases):
    rows = []
    for ph in phases:
        n = len(ph["items"])
        c = sum(1 for i in ph["items"] if i["checked"])
        rows.append((ph["id"], ph["title"], c, n))
    return rows


# ------------------------------------------------------------- plan sync

def stub_paths(root):
    d = os.path.join(root, CONT, "plans")
    if not os.path.isdir(d):
        return []
    return sorted(os.path.join(d, f) for f in os.listdir(d) if f.endswith(".md"))


def split_stub(body):
    """Return (preamble, derived_text or None)."""
    lines = body.split("\n")
    for k, line in enumerate(lines):
        if DERIVED_RE.match(line.strip()):
            return "\n".join(lines[:k]), "\n".join(lines[k + 1:]).strip("\n") + "\n"
    return body, None


def find_moved(root, old):
    st = git("status", "--porcelain", "-M") or ""
    for line in st.split("\n"):
        if line[:1] == "R" and " -> " in line:
            a, b = line[3:].split(" -> ", 1)
            if a.strip('"') == old:
                return b.strip('"')
    log = git("log", "-M", "--diff-filter=R", "--name-status", "--format=") or ""
    for line in log.split("\n"):
        parts = line.split("\t")
        if len(parts) == 3 and parts[0].startswith("R") and parts[1] == old:
            return parts[2]
    return None


def sync_stub(root, path, check_only=False):
    """Returns (status, changes). status: unchanged|updated|stub-edited|missing-source|owned."""
    fm, body = load_doc(path)
    rel = os.path.relpath(path, root)
    src = fm.get("body_path")
    if not src:
        return "owned", []
    srcp = os.path.join(root, src)
    if not os.path.isfile(srcp):
        moved = find_moved(root, src)
        hint = (" (git shows it moved to %s; set body_path to adopt the new path)" % moved) if moved else ""
        return "missing-source", ["%s: body_path %s not found%s" % (rel, src, hint)]
    pre, derived = split_stub(body)
    old_phases, old_removed = parse_outline(derived or "", stub=True)
    edited = derived is not None and fm.get("outline_hash") and text_hash(derived) != fm.get("outline_hash")
    h = blob_hash(srcp)
    if h == fm.get("source_hash") and not edited and derived is not None:
        return "unchanged", []
    new_phases, _ = parse_outline(read(srcp), stub=False)
    changes, removed = assign_ids(new_phases, old_phases, old_removed)
    if edited:
        changes.insert(0, "stub edited directly below the derived marker; original unchanged. "
                          "Restored the derived outline from %s" % src)
    outline = render_outline(new_phases, removed)
    fm["source_hash"] = h
    fm["synced_at"] = now_iso()
    fm["outline_hash"] = text_hash(outline)
    content = dump_fm(fm) + (pre.rstrip("\n") + "\n\n" if pre.strip() else "") + \
        DERIVED_MARK.format(path=src) + "\n" + outline
    if not check_only:
        write(path, content)
    return ("stub-edited" if edited else "updated"), changes


def cmd_sync_plans(args):
    root = repo_root()
    check = "--check" in args
    missing = False
    report = []
    for p in stub_paths(root):
        status, changes = sync_stub(root, p, check_only=check)
        rel = os.path.relpath(p, root)
        if status == "missing-source":
            missing = True
        if status not in ("unchanged", "owned"):
            report.append("%s: %s" % (rel, status))
            report.extend("  - " + c for c in changes)
    if report:
        print("Plan sync:")
        print("\n".join(report))
    else:
        print("Plan sync: all plan stubs current.")
    return 3 if missing else 0


def cmd_outline(args):
    if not args:
        die("usage: outline FILE")
    text = read(args[0])
    fm, body = parse_fm(text)
    stub = args[0].replace("\\", "/").find("/%s/plans/" % CONT) >= 0 or args[0].startswith(CONT + "/plans/")
    if stub:
        _, derived = split_stub(body)
        phases, removed = parse_outline(derived if derived is not None else body, stub=True)
    else:
        phases, removed = parse_outline(text, stub=False)
    print(json.dumps({"front_matter": fm, "phases": phases, "removed": removed}, indent=2, default=str))
    return 0


# ---------------------------------------------------------- workstreams

def ws_dir(root):
    return os.path.join(root, CONT, "workstreams")


def all_workstreams(root):
    d = ws_dir(root)
    out = []
    if os.path.isdir(d):
        for f in sorted(os.listdir(d)):
            if f.endswith(".md"):
                p = os.path.join(d, f)
                fm, body = load_doc(p)
                out.append((p, fm, body))
    return out


def find_ws(root, key):
    if not key:
        return None
    wss = all_workstreams(root)
    for p, fm, b in wss:
        if fm.get("id") == key:
            return p, fm, b
    for p, fm, b in wss:
        if os.path.basename(p)[:-3] == key or slugify(str(fm.get("title", ""))) == slugify(key):
            return p, fm, b
    return None


def opt(args, name, default=None):
    if name in args:
        i = args.index(name)
        if i + 1 < len(args):
            v = args[i + 1]
            del args[i:i + 2]
            return v
        die("%s needs a value" % name)
    return default


def cmd_workstream_new(args):
    root = repo_root()
    branch = opt(args, "--branch", "")
    plans = opt(args, "--plans", "")
    nxt = opt(args, "--next", "")
    status = opt(args, "--status", "active")
    if not args:
        die('usage: workstream-new "TITLE" [--branch B] [--plans pl_a,pl_b] [--next TEXT]')
    title = " ".join(args)
    if status not in WS_OPEN | {"merged", "done", "abandoned"}:
        die("unknown status %s" % status)
    slug = slugify(title)
    path = os.path.join(ws_dir(root), slug + ".md")
    k = 2
    while os.path.exists(path):
        path = os.path.join(ws_dir(root), "%s-%d.md" % (slug, k))
        k += 1
    existing = {fm.get("id") for _, fm, _ in all_workstreams(root)}
    wid = rand_id("ws_")
    while wid in existing:
        wid = rand_id("ws_")
    fm = {
        "id": wid, "title": title,
        "plans": [p for p in plans.split(",") if p] if plans else [],
        "branch": branch or "", "status": status,
        "opened_at": _dt.date.today().isoformat(), "last_activity_at": now_iso(),
        "latest_checkpoint": "", "next_step": nxt,
    }
    write(path, dump_fm(fm) + "Free-form notes the agent may append; never required.\n")
    print(wid)
    print(os.path.relpath(path, root))
    return 0


def checkpoints_for(root, wid):
    d = os.path.join(root, CONT, "checkpoints")
    out = []
    if os.path.isdir(d):
        for f in os.listdir(d):
            if f.endswith(".md"):
                p = os.path.join(d, f)
                try:
                    fm, _ = parse_fm(read(p))
                except ValueError:
                    continue
                if fm.get("workstream") == wid:
                    out.append((str(fm.get("id", "")), p, fm))
    out.sort(key=lambda x: (str(x[2].get("created", "")), x[0]))
    return out


def age_days(iso):
    d = parse_iso(iso)
    if not d:
        return None
    return (_dt.datetime.now(_dt.timezone.utc) - d).days


def cmd_workstream_list(args):
    root = repo_root()
    wss = all_workstreams(root)
    show_all = "--all" in args
    rows = []
    for p, fm, _ in wss:
        if not show_all and fm.get("status") not in WS_OPEN:
            continue
        rows.append((p, fm))
    if not rows:
        print("No unfinished workstreams." if not show_all else "No workstreams.")
        return 0
    order = {"blocked": 0, "waiting": 1, "active": 2, "paused": 3}
    rows.sort(key=lambda r: (order.get(r[1].get("status"), 9), str(r[1].get("last_activity_at", ""))))
    print("Unfinished workstreams:" if not show_all else "Workstreams:")
    for p, fm in rows:
        age = age_days(fm.get("last_activity_at"))
        print("- %s  %s  [%s]%s%s" % (
            fm.get("id"), fm.get("title"), fm.get("status"),
            ("  branch %s" % fm["branch"]) if fm.get("branch") else "",
            ("  last activity %d days ago" % age) if age is not None else ""))
        if fm.get("next_step"):
            print("    next: %s" % fm["next_step"])
    return 0


# ------------------------------------------------------------ checkpoints

def default_branch():
    ref = git("symbolic-ref", "--quiet", "refs/remotes/origin/HEAD")
    if ref:
        return ref.replace("refs/remotes/", "")
    for b in ("origin/main", "main", "origin/master", "master"):
        if git_ok("rev-parse", "--verify", "--quiet", b):
            return b
    return None


def tree_fingerprint():
    """Identical to cont_tree_fp in lib.sh: blob hash of status + "--\n" + diff vs HEAD."""
    def raw(args):
        try:
            return subprocess.run(["git"] + args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout
        except OSError:
            return b""
    ex = ["--", ".", ":(exclude)%s" % CONT]
    data = raw(["status", "--porcelain", "--untracked-files=normal"] + ex) + b"--\n" + \
        raw(["diff", "HEAD", "--no-color", "--no-ext-diff"] + ex)
    return hashlib.sha1(b"blob %d\0" % len(data) + data).hexdigest()


def tree_facts(root):
    st = git("status", "--porcelain", "--", ".", ":(exclude)%s" % CONT) or ""
    dirty = [l[3:].strip('"') for l in st.split("\n") if l.strip()]
    ds = git("diff", "--stat", "HEAD", "--", ".", ":(exclude)%s" % CONT) or ""
    return {
        "code_commit": git("rev-parse", "HEAD") or "",
        "branch": git("branch", "--show-current") or "",
        "worktree": root,
        "machine": socket.gethostname(),
        "dirty_files": dirty[:200],
        "diff_stat": ds.strip().split("\n")[-1].strip() if ds.strip() else "",
    }


def cmd_checkpoint_new(args):
    root = repo_root()
    agent = opt(args, "--agent", "unknown")
    kind = opt(args, "--kind", "agent")
    if not args:
        die("usage: checkpoint-new WORKSTREAM [--agent claude-code|codex] [--kind agent|manual]")
    found = find_ws(root, args[0])
    if not found:
        die("workstream %s not found; create it with: continuity workstream new \"TITLE\"" % args[0])
    wpath, wfm, _ = found
    cid = ulid()
    facts = tree_facts(root)
    fm = {
        "id": cid, "workstream": wfm["id"], "plans": wfm.get("plans") or [],
        "kind": kind, "agent": agent, "created": now_iso(),
    }
    fm.update(facts)
    fm["tests"] = None
    fm["item_claims"] = []
    fm["waiting"] = None
    stamp = _dt.datetime.now(_dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    name = "%s-%s-%s.md" % (stamp, os.path.basename(wpath)[:-3][:40], cid[-8:].lower())
    path = os.path.join(root, CONT, "checkpoints", name)
    body = "".join("## %s\n\n" % s for s in SECTIONS)
    write(path, dump_fm(fm) + body)
    print(os.path.relpath(path, root))
    return 0


def section_text(body, title):
    m = re.search(r"^##\s+%s\s*$(.*?)(?=^##\s|\Z)" % re.escape(title), body, re.M | re.S)
    return m.group(1).strip() if m else None


def validate_checkpoint(root, path, fm, body):
    errs, warns = [], []
    rel = os.path.relpath(path, root).replace("\\", "/")
    if not rel.startswith(CONT + "/checkpoints/"):
        errs.append("checkpoint must live in %s/checkpoints/" % CONT)
    if "checkpoint_commit" in fm:
        errs.append("checkpoint_commit must not be written into the file (it is derived from git)")
    if not fm.get("workstream"):
        errs.append("workstream is required")
    elif not find_ws(root, fm["workstream"]):
        errs.append("workstream %s does not exist" % fm["workstream"])
    if fm.get("kind") not in KINDS:
        errs.append("kind must be one of %s" % ", ".join(sorted(KINDS)))
    if not fm.get("agent"):
        errs.append("agent is required")
    claims = fm.get("item_claims") or []
    if not isinstance(claims, list):
        errs.append("item_claims must be a list")
        claims = []
    for c in claims:
        if not isinstance(c, dict) or not c.get("item"):
            errs.append("each item_claims entry needs item: <id>")
            continue
        r = c.get("rung", "implemented")
        if r not in RUNGS:
            errs.append("item %s: unknown rung %s" % (c.get("item"), r))
        elif r == "verified":
            errs.append("item %s: agents may not claim verified; that rung needs a recorded observation" % c["item"])
        elif r in ("implemented", "tested", "deployed") and not c.get("ref"):
            warns.append("item %s: claim %s without a ref (commit, PR, test run or deploy URL)" % (c["item"], r))
    w = fm.get("waiting")
    if w not in (None, "", {}):
        if not isinstance(w, dict) or w.get("type") not in ("time", "condition"):
            errs.append("waiting must be {type: time|condition, ...}")
        elif w.get("type") == "time" and not parse_iso(str(w.get("until", ""))):
            errs.append("waiting.until must be a date (YYYY-MM-DD)")
    nxt = section_text(body, "Next step")
    if not nxt:
        errs.append('the "## Next step" section is required and must not be empty')
    for s in ("Objective", "Completed since last checkpoint"):
        if not section_text(body, s):
            warns.append('section "%s" is empty' % s)
    return errs, warns


SECRET_PATTERNS = [
    re.compile(r"\bsk-[A-Za-z0-9_-]{16,}"),
    re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    re.compile(r"\bgh[pousr]_[A-Za-z0-9]{30,}"),
    re.compile(r"\bgithub_pat_[A-Za-z0-9_]{30,}"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}"),
    re.compile(r"\beyJ[A-Za-z0-9_-]{15,}\.eyJ[A-Za-z0-9_-]{15,}"),
    re.compile(r"(?i)\b(api[_-]?key|token|secret|password|passwd)\b\s*[:=]\s*['\"]?[A-Za-z0-9/+_=-]{24,}"),
    re.compile(r"(?i)(postgres(?:ql)?|mysql)://[^:\s]+:[^@\s]{6,}@"),
    re.compile(r"\bcmt_[A-Za-z0-9_-]{20,}"),  # a Continuity dashboard key
]


def scan_files(paths):
    hits = []
    for p in paths:
        if not os.path.isfile(p):
            continue
        try:
            txt = read(p)
        except OSError:
            continue
        for n, line in enumerate(txt.split("\n"), 1):
            for pat in SECRET_PATTERNS:
                if pat.search(line):
                    hits.append("%s:%d" % (p, n))
                    break
    return hits


def cmd_secret_scan(args):
    hits = scan_files(args)
    if hits:
        print("Possible secrets (nothing committed):")
        for h in hits:
            print("  " + h)
        return 4
    return 0


def cmd_validate(args):
    root = repo_root()
    if not args:
        die("usage: validate FILE")
    path = os.path.abspath(args[0])
    fm, body = load_doc(path)
    errs, warns = validate_checkpoint(root, path, fm, body)
    for w in warns:
        print("warning: " + w)
    for e in errs:
        print("error: " + e)
    return 2 if errs else 0


def load_last_handoff(sd):
    p = os.path.join(sd, "last-handoff.json")
    if os.path.isfile(p):
        try:
            return json.loads(read(p))
        except ValueError:
            return {}
    return {}


def pending_auto(sd):
    d = os.path.join(sd, "auto")
    if not os.path.isdir(d):
        return []
    return sorted(os.path.join(d, f) for f in os.listdir(d) if f.endswith(".md"))


def cmd_checkpoint_commit(args):
    root = repo_root()
    os.chdir(root)
    summary = opt(args, "--summary", "")
    if not args:
        die("usage: checkpoint-commit FILE [--summary TEXT]")
    path = os.path.abspath(args[0])
    rel = os.path.relpath(path, root)
    if not os.path.isfile(path):
        die("%s not found" % rel)
    committed = git("log", "-1", "--format=%H", "--", rel)
    if committed:
        die("%s is already committed; checkpoints are immutable. Write a new checkpoint instead." % rel, 2)
    fm, body = load_doc(path)
    errs, warns = validate_checkpoint(root, path, fm, body)
    for w in warns:
        print("warning: " + w)
    if errs:
        for e in errs:
            print("error: " + e)
        print("Nothing committed. Fix the checkpoint and run the handoff commit again.")
        return 2
    sd = state_dir()
    # Facts from git are authoritative; they replace anything the agent typed.
    if not fm.get("id"):
        fm["id"] = ulid()
    if not fm.get("created"):
        fm["created"] = now_iso()
    fm.update(tree_facts(root))
    # Fold sessions that ended without a handoff.
    autos = pending_auto(sd)
    folded = []
    if autos:
        lines = ["", "## Sessions that ended without a handoff", ""]
        for a in autos:
            try:
                afm, abody = parse_fm(read(a))
            except ValueError:
                afm, abody = {}, ""
            dirty = section_text(abody, "Working tree status") or ""
            n = 0 if dirty in ("clean", "unavailable", "") else len([l for l in dirty.split("\n") if l.strip()])
            lines.append("- %s, %s on %s at %s: %s dirty file(s); diff %s" % (
                afm.get("created", "?"), afm.get("agent", "?"), afm.get("branch", "?") or "detached",
                str(afm.get("code_commit", ""))[:12], n if dirty != "unavailable" else "unknown",
                (section_text(abody, "Diff stat") or "unknown").split("\n")[0]))
            folded.append(a)
        body = body.rstrip("\n") + "\n" + "\n".join(lines) + "\n"
    write(path, dump_fm(fm) + body)
    # Plan sync rides in the same commit.
    sync_report = []
    for p in stub_paths(root):
        st, ch = sync_stub(root, p)
        if st not in ("unchanged", "owned"):
            sync_report.append("%s: %s" % (os.path.relpath(p, root), st))
            sync_report.extend("  - " + c for c in ch)
    # Workstream update.
    wpath, wfm, wbody = find_ws(root, fm["workstream"])
    nxt = (section_text(body, "Next step") or "").split("\n\n")[0].replace("\n", " ").strip()
    wfm["latest_checkpoint"] = fm["id"]
    wfm["last_activity_at"] = fm["created"]
    if nxt:
        wfm["next_step"] = nxt[:400]
    notes = []
    if not wfm.get("branch") and fm.get("branch"):
        wfm["branch"] = fm["branch"]
    elif wfm.get("branch") and fm.get("branch") and wfm["branch"] != fm["branch"]:
        notes.append("note: workstream branch is %s but this checkpoint was written on %s (left unchanged)"
                     % (wfm["branch"], fm["branch"]))
    if fm.get("workstream_status") in WS_OPEN | {"merged", "done", "abandoned"}:
        wfm["status"] = fm["workstream_status"]
    elif isinstance(fm.get("waiting"), dict) and fm["waiting"].get("type"):
        wfm["status"] = "waiting"
    elif wfm.get("status") in ("waiting",):
        wfm["status"] = "active"
    write(wpath, dump_fm(wfm) + wbody.lstrip("\n"))
    # Secret scan everything about to be committed under .continuity/.
    st = git("status", "--porcelain", "--untracked-files=all", "--", CONT) or ""
    changed = []
    for l in st.split("\n"):
        if l.strip():
            f = l[3:].strip('"')
            if " -> " in f:
                f = f.split(" -> ", 1)[1]
            changed.append(os.path.join(root, f))
    if os.environ.get("CONTINUITY_SECRET_SCAN", "true") != "false":
        hits = scan_files(changed)
        if hits:
            print("Possible secrets found; nothing committed:")
            for h in hits:
                print("  " + os.path.relpath(h.rsplit(":", 1)[0], root) + ":" + h.rsplit(":", 1)[1])
            return 4
    subj = summary or (section_text(body, "Objective") or "").split("\n")[0]
    subj = re.sub(r"\s+", " ", subj).strip()[:60] or "checkpoint"
    wslug = os.path.basename(wpath)[:-3]
    run(["git", "add", "-A", "--", CONT], check=True)
    p = run(["git", "commit", "--only", "-q", "-m", "continuity: %s %s" % (wslug, subj), "--", CONT])
    if p is None or p.returncode != 0:
        print("git commit failed; checkpoint written but not committed:\n%s" % (p.stderr if p else ""))
        return 5
    ckc = git("rev-parse", "HEAD")
    sh = tree_fingerprint()
    write(os.path.join(sd, "last-handoff.json"), json.dumps({
        "id": fm["id"], "workstream": fm["workstream"], "code_commit": fm["code_commit"],
        "checkpoint_commit": ckc, "at": fm["created"], "tree_fp": sh,
    }, indent=1) + "\n")
    if folded:
        pd = os.path.join(sd, "auto", "promoted")
        os.makedirs(pd, exist_ok=True)
        for a in folded:
            shutil.move(a, os.path.join(pd, os.path.basename(a)))
    print("Handoff committed: %s" % ckc[:12])
    print("  checkpoint: %s" % rel)
    print("  workstream: %s (%s) status %s" % (wfm["id"], wfm.get("title"), wfm.get("status")))
    print("  code_commit: %s on %s%s" % (fm["code_commit"][:12], fm.get("branch") or "detached HEAD",
                                         ", %d uncommitted file(s) recorded" % len(fm["dirty_files"])
                                         if fm["dirty_files"] else ""))
    if folded:
        print("  folded %d session snapshot(s) that ended without a handoff" % len(folded))
    for n in notes:
        print("  " + n)
    if sync_report:
        print("\n".join(sync_report))
    print("Not pushed. Only %s/ was committed." % CONT)
    for line in dashboard_after_handoff(root, path, wpath, ckc, fm.get("branch")):
        print(line)
    return 0


# ------------------------------------------------- dashboard (optional)
#
# A computer connected to the Continuity dashboard sends each checkpoint right after the
# handoff commit, so the dashboard shows it before it is pushed. The repository stays the
# record: the dashboard replaces the copy with GitHub's once the commit is pushed (same id).
# Only the checkpoint file and its workstream file are sent; both were secret-scanned by the
# commit step and are scanned again here. Nothing here can fail a handoff: anything that
# cannot be sent waits in a per-computer outbox and goes with the next handoff or pickup.
#
#   ~/.continuity/dashboard.json   {"url": ..., "token": ...}  (mode 600; CONTINUITY_HOME overrides ~/.continuity)
#   ~/.continuity/outbox/<id>.json one queued checkpoint each

INGEST_PATH = "/api/ingest/checkpoint"
SEND_BUDGET_S = 15


def cm_home():
    return os.environ.get("CONTINUITY_HOME") or os.path.join(os.path.expanduser("~"), ".continuity")


def outbox_dir():
    return os.path.join(cm_home(), "outbox")


def dashboard_conf():
    try:
        c = json.loads(read(os.path.join(cm_home(), "dashboard.json")))
    except (OSError, ValueError):
        return None
    return c if isinstance(c, dict) and c.get("url") and c.get("token") else None


def write_private(path, text):
    os.makedirs(os.path.dirname(path), mode=0o700, exist_ok=True)
    tmp = path + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(text)
    os.replace(tmp, path)


def repo_config(root):
    out = {}
    path = os.path.join(root, CONT, "config.yml")
    if os.path.isfile(path):
        for l in read(path).split("\n"):
            m = re.match(r"^([A-Za-z_]+):\s*(.*?)\s*$", l)
            if m:
                out[m.group(1)] = m.group(2)
    return out


def repo_full_name():
    """owner/name of the origin remote (GitHub), or None."""
    url = git("remote", "get-url", "origin") or ""
    m = re.search(r"github\.com[:/]+([^/\s]+)/([^/\s]+?)(?:\.git)?/?$", url.strip())
    return "%s/%s" % (m.group(1), m.group(2)) if m else None


def text_has_secret(text):
    return any(pat.search(line) for line in text.split("\n") for pat in SECRET_PATTERNS)


def queue_checkpoint(root, cp_path, ws_path, commit, branch):
    """Write one checkpoint to the outbox. Returns (queued, message)."""
    repo = repo_full_name()
    if not repo:
        return False, "no GitHub origin remote, so the dashboard cannot tell which project this is"
    fm, _ = load_doc(cp_path)
    payload = {
        "version": 1, "repo": repo, "branch": branch, "checkpoint_commit": commit,
        "checkpoint": {"path": os.path.relpath(cp_path, root).replace("\\", "/"), "text": read(cp_path)},
        "workstream": {"path": os.path.relpath(ws_path, root).replace("\\", "/"), "text": read(ws_path)} if ws_path else None,
    }
    if text_has_secret(payload["checkpoint"]["text"]) or (payload["workstream"] and text_has_secret(payload["workstream"]["text"])):
        return False, "a key-shaped string is in the checkpoint; not sent"
    write_private(os.path.join(outbox_dir(), "%s.json" % fm["id"]), json.dumps(payload) + "\n")
    return True, None


def http_json(method, url, token, body=None, timeout=6):
    """(status, json) from the dashboard; status None when it could not be reached."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": "Bearer " + token, "Content-Type": "application/json", "User-Agent": "continuity"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read().decode("utf-8") or "{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8") or "{}")
        except ValueError:
            return e.code, {}
    except Exception as e:  # offline, DNS, timeout, TLS
        if "CERTIFICATE_VERIFY_FAILED" in str(e) and shutil.which("curl"):
            return curl_json(method, url, token, data, timeout)
        return None, {"error": str(e)[:200]}


def curl_json(method, url, token, data, timeout):
    """Fallback for Pythons without a certificate store (python.org installs on macOS).
    The token goes through curl's stdin config, never the command line."""
    cfg = 'header = "Authorization: Bearer %s"\nheader = "Content-Type: application/json"\n' % token
    args = ["curl", "-sS", "-K", "-", "-X", method, "--max-time", str(timeout), "-w", "\n%{http_code}", url]
    body_file = None
    if data is not None:
        body_file = os.path.join(outbox_dir(), ".body.tmp")
        write_private(body_file, data.decode("utf-8"))
        args[1:1] = ["--data-binary", "@" + body_file]
    try:
        p = subprocess.run(args, input=cfg, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           universal_newlines=True, timeout=timeout + 2)
    except (subprocess.TimeoutExpired, OSError) as e:
        return None, {"error": str(e)[:200]}
    finally:
        if body_file:
            _remove(body_file)
    body, _, code = p.stdout.rpartition("\n")
    if p.returncode != 0 or not code.isdigit() or code == "000":
        return None, {"error": (p.stderr or "curl failed").strip()[:200]}
    try:
        return int(code), json.loads(body or "{}")
    except ValueError:
        return int(code), {}


def flush_outbox(budget=SEND_BUDGET_S):
    """Send what waits in the outbox. Returns report lines; never raises for network trouble."""
    conf = dashboard_conf()
    d = outbox_dir()
    files = sorted(f for f in os.listdir(d) if f.endswith(".json")) if os.path.isdir(d) else []
    if not conf or not files:
        return []
    url = conf["url"].rstrip("/") + INGEST_PATH
    start, sent, known, lines = time.time(), 0, 0, []
    for f in files:
        if time.time() - start > budget:
            break
        path = os.path.join(d, f)
        try:
            body = json.loads(read(path))
        except (OSError, ValueError):
            os.remove(path)
            continue
        status, res = http_json("POST", url, conf["token"], body)
        label = "%s (%s)" % (f[:-5][-8:].lower(), body.get("repo", "?"))
        if status == 200:
            if res.get("status") == "on_github":
                known += 1
            else:
                sent += 1
            _remove(path)
        elif status == 401:
            lines.append("Dashboard: this computer's key was refused (revoked?); %d checkpoint(s) kept. "
                         "Connect it again from the dashboard's Settings." % len(files))
            return lines
        elif status in (400, 404, 413, 422):
            lines.append("Dashboard: %s not sent: %s" % (label, res.get("error", "refused (%s)" % status)))
            _remove(path)
        else:
            left = len([x for x in os.listdir(d) if x.endswith(".json")])
            lines.append("Dashboard: offline or unavailable (%s); %d checkpoint(s) wait in %s and go with the next handoff or pickup."
                         % (res.get("error") or "HTTP %s" % status, left, d.replace(os.path.expanduser("~"), "~")))
            return lines
    if known:
        lines.insert(0, "Dashboard: %d checkpoint(s) already on GitHub; nothing more to send." % known)
    if sent:
        lines.insert(0, "Dashboard: sent %d checkpoint(s); they show there before you push." % sent)
    return lines


def _remove(path):
    try:
        os.remove(path)
    except OSError:
        pass


def dashboard_after_handoff(root, cp_path, ws_path, commit, branch):
    """Called after a successful handoff commit. Never raises."""
    try:
        if os.environ.get("CONTINUITY_DASHBOARD") == "off" or not dashboard_conf():
            return []
        if repo_config(root).get("dashboard", "auto") in ("off", "false"):
            return ["Dashboard: sending is off for this project (config dashboard off)."]
        ok, why = queue_checkpoint(root, cp_path, ws_path, commit, branch)
        lines = [] if ok else ["Dashboard: not sent: %s." % why]
        return lines + flush_outbox()
    except Exception as e:  # the handoff itself already succeeded
        return ["Dashboard: skipped (%s); the handoff is committed." % str(e)[:160]]


def cmd_dashboard(args):
    sub = args[0] if args else "status"
    rest = args[1:]
    conf_path = os.path.join(cm_home(), "dashboard.json")
    if sub == "connect":
        if len(rest) != 2:
            die("usage: dashboard connect URL TOKEN   (Settings > Computers that send checkpoints gives the exact command)")
        url, token = rest[0].rstrip("/"), rest[1]
        if token == "-":
            token = sys.stdin.readline().strip()
        if not re.match(r"^https://[^\s/]+$|^http://(localhost|127\.0\.0\.1)(:\d+)?$", url):
            die("URL must be https://<host> (or http://localhost:<port> for a local dashboard)")
        if not re.match(r"^cmt_[A-Za-z0-9_-]{20,}$", token):
            die("that does not look like a dashboard key (it starts with cmt_)")
        status, res = http_json("GET", url + INGEST_PATH, token)
        if status == 401:
            die("the dashboard refused this key; create a new one in Settings")
        write_private(conf_path, json.dumps({"url": url, "token": token}, indent=1) + "\n")
        if status != 200:
            print("Saved, but the dashboard could not be reached right now (%s). Checkpoints will queue and send later."
                  % (res.get("error") or "HTTP %s" % status))
            return 0
        print("Connected as \"%s\" to %s. Every Continuity project on this computer now sends its checkpoints at /handoff." % (res.get("machine"), url))
        print("Key saved in %s (readable only by you)." % conf_path.replace(os.path.expanduser("~"), "~"))
        _print_repo_status(res)
        return 0
    if sub == "disconnect":
        _remove(conf_path)
        print("Disconnected: this computer no longer sends checkpoints. Revoke its key in the dashboard's Settings too.")
        return 0
    conf = dashboard_conf()
    if not conf:
        print("Not connected. In the dashboard open Settings > Computers that send checkpoints, then run the command it shows.")
        return 0 if sub == "status" else 1
    if sub == "status":
        status, res = http_json("GET", conf["url"] + INGEST_PATH, conf["token"])
        d = outbox_dir()
        waiting = len([f for f in os.listdir(d) if f.endswith(".json")]) if os.path.isdir(d) else 0
        if status == 200:
            print("Connected to %s as \"%s\"." % (conf["url"], res.get("machine")))
            _print_repo_status(res)
        elif status == 401:
            print("Connected to %s, but the key was refused (revoked?). Create a new one in Settings." % conf["url"])
        else:
            print("Connected to %s, but it cannot be reached right now (%s)." % (conf["url"], res.get("error") or "HTTP %s" % status))
        print("%d checkpoint(s) waiting to be sent." % waiting)
        return 0
    if sub == "flush":
        lines = flush_outbox(budget=60)
        print("\n".join(lines) if lines else "Nothing waiting.")
        return 0
    if sub == "send":
        root = repo_root()
        os.chdir(root)
        if rest:
            cp = os.path.abspath(rest[0])
        else:
            last = load_last_handoff(state_dir())
            found = [p for p in _checkpoint_files(root) if load_doc(p)[0].get("id") == last.get("id")]
            if not found:
                die("no handoff recorded in this worktree yet; give a checkpoint file")
            cp = found[0]
        rel = os.path.relpath(cp, root)
        commit = git("log", "--diff-filter=A", "-1", "--format=%H", "--", rel)
        if not commit:
            die("%s is not committed; run the handoff commit first" % rel)
        fm, _ = load_doc(cp)
        w = find_ws(root, fm.get("workstream") or "")
        ok, why = queue_checkpoint(root, cp, w[0] if w else None, commit, fm.get("branch"))
        if not ok:
            die(why)
        lines = flush_outbox(budget=60)
        print("\n".join(lines) if lines else "Queued.")
        return 0
    die("usage: dashboard connect URL TOKEN | status | flush | send [FILE] | disconnect")


def _checkpoint_files(root):
    d = os.path.join(root, CONT, "checkpoints")
    return sorted(os.path.join(d, f) for f in os.listdir(d) if f.endswith(".md")) if os.path.isdir(d) else []


def _print_repo_status(res):
    repo = repo_full_name() if git("rev-parse", "--show-toplevel") else None
    if not repo:
        return
    known = [r.lower() for r in res.get("repos", [])]
    print("This project (%s) is %s." % (repo, "connected to the dashboard" if repo.lower() in known
                                         else "NOT connected to the dashboard yet; connect it in Settings"))


# ------------------------------------------------------------- pickup

def code_commits_since(base):
    revs = git("rev-list", "--reverse", "%s..HEAD" % base)
    if revs is None:
        return None
    out = []
    for r in [x for x in revs.split("\n") if x]:
        p = run(["git", "diff", "--quiet", "%s^" % r, r, "--", ".", ":(exclude)%s" % CONT])
        if p is not None and p.returncode == 1:
            out.append(git("log", "-1", "--format=%h %as %an: %s", r))
        elif p is not None and p.returncode not in (0, 1):
            out.append(git("log", "-1", "--format=%h %as %an: %s", r))
    return out


def gh_prs(branch):
    if not branch or not shutil.which("gh"):
        return None
    p = run(["gh", "pr", "list", "--head", branch, "--state", "all", "--json", "number,state,title,mergedAt",
             "--limit", "5"], timeout=8)
    if p is None or p.returncode != 0:
        return None
    try:
        return json.loads(p.stdout or "[]")
    except ValueError:
        return None


def facts_report(root):
    f = tree_facts(root)
    sd = state_dir()
    last = load_last_handoff(sd)
    print("Facts for handoff (from git, %s):" % now_iso())
    print("  branch: %s" % (f["branch"] or "detached HEAD"))
    print("  HEAD: %s" % f["code_commit"][:12])
    print("  uncommitted files (outside %s/): %d" % (CONT, len(f["dirty_files"])))
    for d in f["dirty_files"][:30]:
        print("    %s" % d)
    if f["diff_stat"]:
        print("  diff: %s" % f["diff_stat"])
    if last.get("code_commit"):
        cs = code_commits_since(last["code_commit"])
        print("  last handoff in this worktree: %s (%s)" % (last.get("id"), last.get("at")))
        if cs is None:
            print("  commits since last handoff: unknown (%s not in this history)" % last["code_commit"][:12])
        else:
            print("  code commits since last handoff: %d" % len(cs))
            for c in cs[:20]:
                print("    %s" % c)
    else:
        print("  no handoff recorded in this worktree yet")
    prs = gh_prs(f["branch"])
    if prs is not None:
        print("  pull requests for this branch: %s" % (", ".join("#%s %s" % (p["number"], p["state"]) for p in prs)
                                                       or "none"))
    else:
        print("  pull requests: unavailable (gh missing or not authenticated)")
    autos = pending_auto(sd)
    if autos:
        print("  sessions that ended without a handoff: %d (will be folded into the next checkpoint)" % len(autos))
    wss = [(p, fm) for p, fm, _ in all_workstreams(root) if fm.get("status") in WS_OPEN]
    print("  unfinished workstreams:")
    if not wss:
        print("    none (create one with: sh .continuity/continuity workstream new \"TITLE\")")
    for p, fm in wss:
        mark = "  <- branch matches" if fm.get("branch") and fm.get("branch") == f["branch"] else ""
        print("    %s  %s [%s]%s" % (fm.get("id"), fm.get("title"), fm.get("status"), mark))
    return 0


def cmd_pickup(args):
    root = repo_root()
    os.chdir(root)
    if "--facts" in args:
        return facts_report(root)
    if os.environ.get("CONTINUITY_DASHBOARD") != "off":
        try:
            for line in flush_outbox(budget=8):
                print(line)
        except Exception:
            pass
    if not args:
        return project_overview(root)
    found = find_ws(root, args[0])
    if not found:
        die("workstream %s not found" % args[0])
    wpath, wfm, _ = found
    cps = checkpoints_for(root, wfm["id"])
    sd = state_dir()
    print("# Pickup: %s (%s)" % (wfm.get("title"), wfm["id"]))
    print("status %s; branch %s; opened %s" % (wfm.get("status"), wfm.get("branch") or "(none)",
                                              wfm.get("opened_at", "?")))
    sync_lines = []
    for p in stub_paths(root):
        st, ch = sync_stub(root, p)
        if st not in ("unchanged", "owned"):
            sync_lines.append("%s: %s" % (os.path.relpath(p, root), st))
            sync_lines.extend("  - " + c for c in ch)
    print("\n## Staleness (checked %s)" % now_iso())
    cp = cps[-1] if cps else None
    issues = 0
    if not cp:
        print("- no checkpoint yet for this workstream")
    else:
        _, cpath, cfm = cp
        age = age_days(cfm.get("created"))
        print("- latest checkpoint: %s, %s, by %s, %s" % (
            os.path.relpath(cpath, root), cfm.get("kind"), cfm.get("agent"),
            ("%d day(s) old" % age) if age is not None else "age unknown"))
        if age is not None and age > 14:
            print("  STALE: older than 14 days")
            issues += 1
        if cfm.get("kind") in ("auto", "onboarding"):
            print("  NOTE: %s checkpoint; written without an agent's judgment, treat its claims as unverified"
                  % cfm.get("kind"))
        base = str(cfm.get("code_commit") or "")
        head = git("rev-parse", "HEAD") or ""
        cur = git("branch", "--show-current") or ""
        if base and not git_ok("cat-file", "-e", base + "^{commit}"):
            print("- code_commit %s is not in this clone's history (rebased, other clone, or shallow)" % base[:12])
            issues += 1
        elif base:
            cs = code_commits_since(base)
            if cs is None:
                print("- cannot compare HEAD to code_commit %s" % base[:12])
            elif not cs:
                print("- no code commits since the checkpoint (HEAD %s; continuity-only commits ignored)" % head[:12])
            else:
                issues += 1
                print("- %d code commit(s) since the checkpoint's code_commit %s:" % (len(cs), base[:12]))
                for c in cs[:20]:
                    print("    %s" % c)
            anc = run(["git", "merge-base", "--is-ancestor", base, "HEAD"])
            if anc is not None and anc.returncode == 1:
                print("- HEAD does not contain code_commit (different branch or rewritten history)")
                issues += 1
        cb = cfm.get("branch") or ""
        if cb:
            if not git_ok("rev-parse", "--verify", "--quiet", "refs/heads/%s" % cb):
                db = default_branch()
                merged = db and base and run(["git", "merge-base", "--is-ancestor", base, db]).returncode == 0
                print("- branch %s no longer exists locally%s" % (
                    cb, "; its code_commit is in %s (likely merged: verify on %s)" % (db, db) if merged
                    else "; not found in %s (possibly orphaned)" % (db or "the default branch")))
                issues += 1
            elif cb != cur:
                print("- checkpoint branch is %s; you are on %s" % (cb, cur or "detached HEAD"))
                issues += 1
        if cfm.get("dirty_files"):
            print("- the checkpoint recorded %d uncommitted file(s) on %s: %s" % (
                len(cfm["dirty_files"]), cfm.get("machine", "?"), ", ".join(cfm["dirty_files"][:8])))
        prs = gh_prs(cb or cur)
        if prs:
            print("- pull requests now: %s" % ", ".join("#%s %s" % (p["number"], p["state"]) for p in prs))
    f = tree_facts(root)
    if f["dirty_files"]:
        print("- working tree now has %d uncommitted file(s): %s" % (len(f["dirty_files"]),
                                                                 ", ".join(f["dirty_files"][:8])))
    created = parse_iso(cp[2].get("created")) if cp else None
    newer = []
    for a in pending_auto(sd):
        try:
            afm, _ = parse_fm(read(a))
        except ValueError:
            continue
        ad = parse_iso(afm.get("created"))
        if not created or (ad and ad > created):
            newer.append((a, afm))
    if newer:
        issues += 1
        print("- %d session(s) in this worktree ended without a handoff after the checkpoint:" % len(newer))
        for a, afm in newer:
            print("    %s %s on %s (snapshot %s)" % (afm.get("created"), afm.get("agent"),
                                                    afm.get("branch") or "?", os.path.basename(a)))
    if issues == 0:
        print("- nothing stale found")
    if sync_lines:
        print("\n## Plan documents changed since their last sync (stubs refreshed, uncommitted)")
        print("\n".join(sync_lines))
    print("\n## Plans in scope")
    stubs = {}
    for sp in stub_paths(root):
        sfm, sbody = load_doc(sp)
        stubs[sfm.get("id")] = (sfm, sbody)

    def phase_line(ref):
        pid, _, ph = str(ref).partition("/")
        if pid not in stubs:
            return None
        rfm, rbody = stubs[pid]
        _, rd = split_stub(rbody)
        rph, _ = parse_outline(rd if rd is not None else rbody, stub=True)
        title = next((p["title"] for p in rph if p["id"] == ph), ph or "")
        st = (rfm.get("phase_status") or {}).get(ph) if isinstance(rfm.get("phase_status"), dict) else None
        return "%s, %s%s" % (rfm.get("title"), title, (": " + str(st)) if st else "")
    plan_ids = set(wfm.get("plans") or [])
    if cp:
        plan_ids |= set(cp[2].get("plans") or [])
    shown = False
    for p in stub_paths(root):
        pfm, pbody = load_doc(p)
        if pfm.get("id") not in plan_ids:
            continue
        shown = True
        _, derived = split_stub(pbody)
        phases, _ = parse_outline(derived if derived is not None else pbody, stub=True)
        print("- %s %s [%s]%s" % (pfm.get("id"), pfm.get("title"), pfm.get("status"),
                                  ("  source: %s" % pfm["body_path"]) if pfm.get("body_path") else ""))
        for ref in pfm.get("implements") or []:
            pl = phase_line(ref)
            if pl:
                print("    roadmap position: %s" % pl)
        pst = pfm.get("phase_status") if isinstance(pfm.get("phase_status"), dict) else {}
        for pid, title, c, n in phase_counts(phases):
            parts = []
            if pst.get(pid):
                parts.append(str(pst[pid]))
            if n:
                parts.append("%d of %d checked" % (c, n))
            print("    %s %s%s" % (pid, title, (": " + "; ".join(parts)) if parts else ""))
    if not shown:
        print("- none linked")
    if cp:
        _, cpath, cfm = cp
        w = cfm.get("waiting")
        if isinstance(w, dict) and w.get("type"):
            print("\n## Waiting")
            if w.get("type") == "time":
                until = parse_iso(str(w.get("until")))
                due = until and until.date() <= _dt.date.today()
                print("- until %s (%s): observing %s; decide: %s" % (
                    w.get("until"), "DUE" if due else "not yet due", w.get("observing", "?"), w.get("decide", "?")))
            else:
                print("- condition: %s; decide: %s" % (w.get("condition", w.get("observing", "?")),
                                                      w.get("decide", "?")))
        body = read(cpath)
        print("\n## Next step (from the checkpoint)")
        print(section_text(split_front_matter(body)[1], "Next step") or "(empty)")
        print("\nRead the full checkpoint: %s" % os.path.relpath(cpath, root))
    print("\nVerify the items above before changing code. Do not trust the checkpoint where it disagrees with git.")
    return 0


# ------------------------------------------------------- map / overview

OPEN_PLAN = {"active", "blocked", "waiting", "unknown"}
SHELF_PLAN = {"paused", "stale"}
CLOSED_PLAN = {"done", "superseded", "cancelled"}


def load_project(root):
    p = os.path.join(root, CONT, "project.md")
    if not os.path.isfile(p):
        return {}, ""
    return load_doc(p)


def plan_index(root):
    out = {}
    for sp in stub_paths(root):
        fm, body = load_doc(sp)
        _, derived = split_stub(body)
        phases, _ = parse_outline(derived if derived is not None else body, stub=True)
        out[fm.get("id")] = {"fm": fm, "phases": phases, "path": sp}
    return out


def _plan_line(pl, indent):
    fm = pl["fm"]
    counts = [(c, n) for _, _, c, n in phase_counts(pl["phases"]) if n]
    tally = ""
    if counts:
        tally = "  %d of %d checked" % (sum(c for c, _ in counts), sum(n for _, n in counts))
    return "%s- %s [%s, %s]%s  (%s)" % (indent, fm.get("title"), fm.get("kind"), fm.get("status"), tally,
                                         fm.get("body_path") or os.path.basename(pl["path"]))


def render_map(root, show_all=False, out=None):
    """Roadmap phases, each with the plans that implement it. Open work is
    expanded; paused and stale are listed briefly; completed plans are
    collapsed to a count unless show_all."""
    w = out or sys.stdout
    plans = plan_index(root)
    roadmaps = [p for p in plans.values() if p["fm"].get("kind") == "roadmap"]
    placed = set()

    def under(ref):
        return [p for p in plans.values() if ref in (p["fm"].get("implements") or [])]

    def block(group, indent):
        opened = [p for p in group if p["fm"].get("status") in OPEN_PLAN]
        shelved = [p for p in group if p["fm"].get("status") in SHELF_PLAN]
        closed = [p for p in group if p["fm"].get("status") in CLOSED_PLAN]
        for p in opened:
            w.write(_plan_line(p, indent) + "\n")
        for p in shelved:
            w.write(_plan_line(p, indent) + "\n")
        if closed:
            if show_all:
                for p in sorted(closed, key=lambda x: str(x["fm"].get("title"))):
                    w.write(_plan_line(p, indent) + "\n")
            else:
                w.write("%s- %d completed plan(s), collapsed (show with: map --all)\n" % (indent, len(closed)))
        for p in group:
            placed.add(p["fm"].get("id"))

    for rm in roadmaps:
        fm = rm["fm"]
        placed.add(fm.get("id"))
        pst = fm.get("phase_status") if isinstance(fm.get("phase_status"), dict) else {}
        w.write("Roadmap: %s (%s)\n" % (fm.get("title"), fm.get("body_path") or fm.get("id")))
        for ph in rm["phases"]:
            ref = "%s/%s" % (fm.get("id"), ph["id"])
            st = pst.get(ph["id"])
            w.write("  %s%s\n" % (ph["title"], (" [%s]" % st) if st else ""))
            block(under(ref), "    ")
    rest = [p for pid, p in plans.items() if pid not in placed]
    if rest:
        w.write("Other plans (not tied to a roadmap phase)\n")
        block(rest, "  ")
    if not plans:
        w.write("No plans registered yet. Run the continuity-onboard skill.\n")


def cmd_map(args):
    root = repo_root()
    render_map(root, show_all="--all" in args)
    return 0


def project_overview(root):
    """Pick Up Where I Left Off, project level: goal, roadmap position,
    unfinished workstreams, plan map, recent activity, context documents."""
    pfm, pbody = load_project(root)
    print("# %s" % (pfm.get("title") or os.path.basename(root)))
    goal = section_text(pbody, "Goal") if pbody else None
    if goal:
        print("\n## Goal\n" + goal)
    print("\n## Unfinished workstreams")
    rc_buf = []
    wss = [(p, fm) for p, fm, _ in all_workstreams(root) if fm.get("status") in WS_OPEN]
    order = {"blocked": 0, "waiting": 1, "active": 2, "paused": 3}
    wss.sort(key=lambda r: (order.get(r[1].get("status"), 9), str(r[1].get("last_activity_at", ""))))
    if not wss:
        print("- none")
    for p, fm in wss:
        age = age_days(fm.get("last_activity_at"))
        cps = checkpoints_for(root, fm["id"])
        due = ""
        if cps:
            wt = cps[-1][2].get("waiting")
            if isinstance(wt, dict) and wt.get("type") == "time":
                until = parse_iso(str(wt.get("until")))
                if until:
                    due = "; waiting until %s%s" % (wt.get("until"), " (DUE)" if until.date() <= _dt.date.today()
                                                    else "")
        print("- %s  %s [%s%s]%s" % (fm["id"], fm.get("title"), fm.get("status"), due,
                                    ("  last activity %d day(s) ago" % age) if age is not None else ""))
        if fm.get("next_step"):
            print("    next: %s" % fm["next_step"])
    print("\n## Plan map")
    render_map(root)
    cps = []
    d = os.path.join(root, CONT, "checkpoints")
    if os.path.isdir(d):
        for f in os.listdir(d):
            if f.endswith(".md"):
                try:
                    cfm, cbody = parse_fm(read(os.path.join(d, f)))
                except ValueError:
                    continue
                cps.append((str(cfm.get("created", "")), cfm, cbody, f))
    cps.sort(key=lambda x: x[0], reverse=True)
    real = [c for c in cps if c[1].get("kind") in ("agent", "manual")]
    print("\n## Recent activity")
    if not real:
        print("- no agent checkpoints yet (only onboarding records)")
    for created, cfm, cbody, f in real[:3]:
        obj = (section_text(cbody, "Objective") or "").split("\n")[0]
        print("- %s %s: %s" % (created[:10], cfm.get("agent"), obj[:160]))
    ctx = pfm.get("context") if isinstance(pfm.get("context"), list) else []
    if ctx:
        print("\n## Project context documents")
        for c in ctx:
            if isinstance(c, dict):
                print("- %s: %s" % (c.get("doc"), c.get("why", "")))
            else:
                print("- %s" % c)
    print("\nTo resume one workstream, run the pickup skill with its id (ws_...).")
    return 0


# --------------------------------------------------------------- config

def cmd_config(args):
    root = repo_root()
    if len(args) != 2:
        die("usage: config KEY VALUE   (e.g. config nudge off)")
    key, val = args
    if key == "nudge" and val in ("on", "off"):
        val = "true" if val == "on" else "false"
    path = os.path.join(root, CONT, "config.yml")
    text = read(path) if os.path.isfile(path) else ""
    lines = text.split("\n") if text else []
    done = False
    for i, l in enumerate(lines):
        if re.match(r"^%s:" % re.escape(key), l):
            lines[i] = "%s: %s" % (key, val)
            done = True
    if not done:
        if lines and lines[-1] == "":
            lines.insert(len(lines) - 1, "%s: %s" % (key, val))
        else:
            lines.append("%s: %s" % (key, val))
    new = "\n".join(lines)
    if not new.endswith("\n"):
        new += "\n"
    write(path, new)
    print("%s: %s" % (key, val))
    return 0


# ------------------------------------------------------- install / remove

def hook_spec(root):
    return json.loads(read(os.path.join(root, CONT, "hooks.json")))


def our_commands(spec):
    cmds = set()
    for groups in spec["hooks"].values():
        for g in groups:
            for h in g.get("hooks", []):
                cmds.add(h.get("command"))
    return cmds


def merge_hooks(existing, spec):
    out = json.loads(json.dumps(existing))
    hooks = out.setdefault("hooks", {})
    if not isinstance(hooks, dict):
        raise ValueError("existing 'hooks' is not an object")
    for event, groups in spec["hooks"].items():
        cur = hooks.setdefault(event, [])
        if not isinstance(cur, list):
            raise ValueError("existing hooks.%s is not a list" % event)
        have = {h.get("command") for g in cur if isinstance(g, dict) for h in g.get("hooks", [])}
        for g in groups:
            if not any(h.get("command") in have for h in g["hooks"]):
                cur.append(json.loads(json.dumps(g)))
    return out


def strip_hooks(existing, cmds):
    out = json.loads(json.dumps(existing))
    hooks = out.get("hooks")
    if not isinstance(hooks, dict):
        return out
    for event in list(hooks.keys()):
        groups = hooks[event]
        if not isinstance(groups, list):
            continue
        new_groups = []
        for g in groups:
            if isinstance(g, dict) and isinstance(g.get("hooks"), list):
                g = dict(g)
                g["hooks"] = [h for h in g["hooks"] if h.get("command") not in cmds]
                if not g["hooks"]:
                    continue
            new_groups.append(g)
        if new_groups:
            hooks[event] = new_groups
        else:
            del hooks[event]
    if not hooks:
        del out["hooks"]
    return out


def json_text(obj):
    return json.dumps(obj, indent=2, ensure_ascii=False) + "\n"


def apply_file(root, rel, new, assume_yes, label):
    path = os.path.join(root, rel)
    old = read(path) if os.path.isfile(path) else ""
    if old == new:
        print("= %s: already up to date" % rel)
        return False
    print("\n--- %s: %s" % (label, rel))
    show_diff(rel, old, new)
    if confirm("Write %s?" % rel, assume_yes):
        if old and rel.endswith(".json") and not os.path.exists(path + ".bak"):
            shutil.copy2(path, path + ".bak")
        write(path, new)
        print("  wrote %s" % rel)
        return True
    return False


FULL_BLOCK = """{start}
## Continuity checkpoints (Stage 0)

This repository uses `.continuity/` to record where each workstream stands, so any agent can pick up where the last one stopped. It is in addition to this file's own end-of-work and handoff steps, never instead of them.

- At a natural stopping point, run the handoff skill (`/handoff` in Claude Code, `$handoff` in Codex). It writes a checkpoint and commits only `.continuity/`. Then still complete this repository's own handoff requirements (for example its status document).
- When starting or returning to work, run the pickup skill (`/pickup` in Claude Code, `$pickup` in Codex) to list unfinished workstreams and verify one against git before changing code.
- Tick checklist items in the plan's original document (named by `body_path` in its `.continuity/plans/` stub), never in the stub.
- The scripts never push. Checkpoint commits touch only `.continuity/`.
{end}
"""

POINTER_BLOCK = """{start}
Continuity checkpoints: see the "Continuity checkpoints" section of {primary}. Use `/handoff` (Codex: `$handoff`) at stopping points and `/pickup` (`$pickup`) when resuming; both are in addition to this repository's own handoff steps.
{end}
"""


def instruction_targets(root):
    """[(file, template)]: the full block goes in the main instruction file, a pointer in the other.

    When both AGENTS.md and CLAUDE.md exist, the longer one is treated as the main file: repositories
    keep their rules in one of them and make the other a short pointer to it."""
    have = [f for f in ("AGENTS.md", "CLAUDE.md") if os.path.isfile(os.path.join(root, f))]
    if len(have) < 2:
        return [(f, FULL_BLOCK) for f in have]

    def size(f):
        return len(without_block(read(os.path.join(root, f))))
    primary = max(have, key=lambda f: (size(f), f == "AGENTS.md"))
    other = [f for f in have if f != primary][0]
    return [(primary, FULL_BLOCK), (other, POINTER_BLOCK)]


def with_block(text, block):
    if BLOCK_START in text and BLOCK_END in text:
        pre = text.split(BLOCK_START)[0]
        post = text.split(BLOCK_END, 1)[1]
        return pre + block.rstrip("\n") + post if post.startswith("\n") else pre + block + post
    sep = "" if text.endswith("\n\n") else ("\n" if text.endswith("\n") else "\n\n")
    return text + sep + block


def without_block(text):
    if BLOCK_START not in text or BLOCK_END not in text:
        return text
    pre = text.split(BLOCK_START)[0]
    post = text.split(BLOCK_END, 1)[1]
    return pre.rstrip("\n") + "\n" + post.lstrip("\n") if post.strip() else pre.rstrip("\n") + "\n"


# Everything Continuity commits: checkpoints and plans (.continuity/) and, on tooling
# updates, its three skills. A push touching only these never needs a deploy.
PATHS_IGNORE = [".continuity/**"] + [
    p for s in ("handoff", "pickup", "continuity-onboard")
    for p in (".agents/skills/%s/**" % s, ".claude/skills/%s" % s, ".claude/skills/%s/**" % s)]


def inspect_deploy(root):
    print("\n--- Deployment configuration (inspection only; nothing is written here)")
    found = False
    for dp, dn, fn in os.walk(root):
        dn[:] = [d for d in dn if d not in (".git", "node_modules", ".next", ".vercel")]
        if "vercel.json" in fn:
            found = True
            rel = os.path.relpath(os.path.join(dp, "vercel.json"), root)
            try:
                vj = json.loads(read(os.path.join(dp, "vercel.json")))
            except ValueError:
                print("  %s: not valid JSON; left alone" % rel)
                continue
            if "ignoreCommand" in vj:
                print("  %s already has ignoreCommand: %r. Not changed. To combine, run the existing command"
                      " and .continuity/scripts/vercel-ignore.sh and build if either says build." % (rel, vj["ignoreCommand"]))
            else:
                print("  %s has no ignoreCommand. Proposed (not applied):" % rel)
                print('    "ignoreCommand": "sh .continuity/scripts/vercel-ignore.sh"')
                print("  Check the Vercel project's Git settings first: a file ignoreCommand overrides the dashboard one.")
    if not found:
        print("  no vercel.json in the repository. If a Vercel project builds this repo through Git, its"
              " Ignored Build Step lives in the dashboard; check it before adding one.")
    vi = os.path.join(root, ".vercelignore")
    if os.path.isfile(vi):
        lines = [l.strip() for l in read(vi).split("\n")]
        missing = [p for p in ("/.continuity/", "/.agents/", "/.codex/") if p not in lines and p.strip("/") not in lines]
        if missing:
            print("  .vercelignore does not exclude %s; CLI deploys from this repo would upload them. Proposed"
                  " addition (not applied): %s" % (", ".join(missing), " ".join(missing)))
    wf = os.path.join(root, ".github", "workflows")
    if os.path.isdir(wf):
        for f in sorted(os.listdir(wf)):
            if not f.endswith((".yml", ".yaml")):
                continue
            txt = read(os.path.join(wf, f))
            if re.search(r"^\s*push\s*:", txt, re.M) or re.search(r"^on:\s*\[?.*push", txt, re.M):
                missing = [g for g in PATHS_IGNORE if g not in txt]
                if not missing:
                    print("  .github/workflows/%s runs on push and already ignores every Continuity path." % f)
                    continue
                print("  .github/workflows/%s runs on push; %s. Proposed (not applied) under on.push:\n"
                      "      paths-ignore:\n%s" % (
                          f, "a push of only checkpoints or tooling updates would run it" if ".continuity" not in txt
                          else "it mentions .continuity but a push of only a tooling update would still run it",
                          "".join("        - '%s'\n" % g for g in PATHS_IGNORE).rstrip("\n")))


def cmd_install(args):
    root = repo_root()
    os.chdir(root)
    yes = "--yes" in args
    mode = opt(list(args), "--skills-mode", None)
    for d in ("plans", "workstreams", "checkpoints", "onboarding"):
        os.makedirs(os.path.join(root, CONT, d), exist_ok=True)
    cfg = os.path.join(root, CONT, "config.yml")
    if not os.path.isfile(cfg):
        write(cfg, "nudge: true\nnudge_minutes: 20\ndone_at_default: implemented\nskills_mode: link\n"
                   "deploy_rule: none\nsecret_scan: true\n")
        print("+ created %s/config.yml" % CONT)
    if mode:
        cmd_config(["skills_mode", mode])
    spec = hook_spec(root)
    # Claude Code: hooks live under "hooks" in .claude/settings.json (project, committed).
    for rel, label in ((".claude/settings.json", "Claude Code hooks"), (".codex/hooks.json", "Codex hooks")):
        p = os.path.join(root, rel)
        try:
            cur = json.loads(read(p)) if os.path.isfile(p) else {}
        except ValueError:
            print("! %s is not valid JSON; not touched. Fix it, then re-run init." % rel)
            continue
        try:
            merged = merge_hooks(cur, spec)
        except ValueError as e:
            print("! %s: %s; not touched." % (rel, e))
            continue
        apply_file(root, rel, json_text(merged), yes, label)
    # Skills for Claude Code: link (or copy) .claude/skills/<name> -> .agents/skills/<name>.
    mode = None
    m = re.search(r"^skills_mode:\s*(\w+)", read(cfg), re.M)
    mode = m.group(1) if m else "link"
    src_dir = os.path.join(root, ".agents", "skills")
    names = sorted(n for n in os.listdir(src_dir) if os.path.isfile(os.path.join(src_dir, n, "SKILL.md")) and
                   n in ("handoff", "pickup", "continuity-onboard"))
    print("\n--- Claude Code skills (%s mode): %s" % (mode, ", ".join(names)))
    if confirm("Install skills into .claude/skills/?", yes):
        os.makedirs(os.path.join(root, ".claude", "skills"), exist_ok=True)
        for n in names:
            dst = os.path.join(root, ".claude", "skills", n)
            target = os.path.join("..", "..", ".agents", "skills", n)
            if os.path.islink(dst):
                if os.readlink(dst) == target and mode == "link":
                    print("  = %s" % n)
                    continue
                os.unlink(dst)
            elif os.path.exists(dst):
                if os.path.isfile(os.path.join(dst, MANAGED_MARK)):
                    shutil.rmtree(dst)
                else:
                    print("  ! .claude/skills/%s exists and is not managed by continuity; skipped" % n)
                    continue
            if mode == "copy":
                shutil.copytree(os.path.join(src_dir, n), dst)
                write(os.path.join(dst, MANAGED_MARK), "copied from .agents/skills/%s; re-run init after editing\n" % n)
            else:
                os.symlink(target, dst)
            print("  + .claude/skills/%s (%s)" % (n, mode))
    # Agent instruction files: append a delimited block, preserve everything else.
    targets = instruction_targets(root)
    primary = targets[0][0] if targets else None
    if not targets:
        print("\n= no AGENTS.md or CLAUDE.md; skills still work, no instruction block written")
    for rel, tmpl in targets:
        p = os.path.join(root, rel)
        old = read(p)
        new = with_block(old, tmpl.format(start=BLOCK_START, end=BLOCK_END, primary=primary))
        apply_file(root, rel, new, yes, "instruction block")
    inspect_deploy(root)
    print("\nCodex: start codex in this repo, open /hooks, and trust the three continuity hooks.")
    print("Codex re-asks for trust whenever a hook definition changes. Project hooks load only for trusted projects.")
    return 0


def cmd_uninstall(args):
    root = repo_root()
    os.chdir(root)
    yes = "--yes" in args
    hooks_only = "--hooks-only" in args
    cmds = our_commands(hook_spec(root))
    for rel in (".claude/settings.json", ".codex/hooks.json"):
        p = os.path.join(root, rel)
        if not os.path.isfile(p):
            continue
        try:
            cur = json.loads(read(p))
        except ValueError:
            print("! %s not valid JSON; restore it from %s.bak if needed" % (rel, rel))
            continue
        new = strip_hooks(cur, cmds)
        if new == {} and rel == ".codex/hooks.json":
            print("\n--- remove %s (only continuity hooks were in it)" % rel)
            if confirm("Delete %s?" % rel, yes):
                os.remove(p)
            continue
        apply_file(root, rel, json_text(new), yes, "remove continuity hooks")
    if hooks_only:
        return 0
    sk = os.path.join(root, ".claude", "skills")
    for n in ("handoff", "pickup", "continuity-onboard"):
        dst = os.path.join(sk, n)
        if os.path.islink(dst) and ".agents/skills" in os.readlink(dst):
            if confirm("Remove .claude/skills/%s link?" % n, yes):
                os.unlink(dst)
        elif os.path.isfile(os.path.join(dst, MANAGED_MARK)):
            if confirm("Remove copied .claude/skills/%s?" % n, yes):
                shutil.rmtree(dst)
    for rel in ("AGENTS.md", "CLAUDE.md"):
        p = os.path.join(root, rel)
        if os.path.isfile(p):
            old = read(p)
            new = without_block(old)
            if new != old:
                apply_file(root, rel, new, yes, "remove instruction block")
    sd = state_dir()
    if confirm("Delete this worktree's continuity state (%s)?" % sd, yes):
        shutil.rmtree(sd, ignore_errors=True)
    print("\n.continuity/ and .agents/skills/ are left in place (committed history is preserved).")
    if "--purge" in args:
        print("--purge: removing .continuity/ and the continuity skills from the repository in one commit.")
        if confirm("git rm and commit?", yes):
            paths = [CONT] + [".agents/skills/%s" % n for n in ("handoff", "pickup", "continuity-onboard")
                              if os.path.isdir(os.path.join(root, ".agents/skills", n))]
            run(["git", "rm", "-r", "-q", "--"] + paths, check=True)
            run(["git", "commit", "-q", "-m", "continuity: uninstall (purge)", "--"] + paths, check=True)
            print("  committed; undo with git revert HEAD")
    print("Deployment settings were never written by init; nothing to undo there unless you applied a proposal by hand.")
    return 0


# ------------------------------------------------------------ onboarding

KW_HEAD = re.compile(r"\b(phase|roadmap|milestone|plan|checklist|todo|to-do|audit|migration|launch|step|stage|"
                     r"increment|next|status|backlog|gap|task|release|rollout|investigation|readiness)\b", re.I)
KW_FILE = re.compile(r"(plan|roadmap|phase|status|task|gap|audit|todo|checklist|readiness|handoff|backlog|"
                     r"proposal|release|investigation|open[-_]questions|decisions)", re.I)


def cmd_onboard_inventory(args):
    root = repo_root()
    os.chdir(root)
    files = (git("ls-files") or "").split("\n")
    exts = (".md", ".mdx", ".markdown", ".txt")
    skip = (CONT + "/", ".agents/skills/", "node_modules/", ".claude/skills/")
    shallow = (git("rev-parse", "--is-shallow-repository") == "true")
    hist = {}
    log = run(["git", "log", "--format=__C__%x09%aI%x09%an", "--name-only", "--no-renames"], timeout=120)
    if log and log.returncode == 0:
        date = author = None
        for line in log.stdout.split("\n"):
            if line.startswith("__C__\t"):
                _, date, author = line.split("\t", 2)
                continue
            if line and date:
                h = hist.get(line)
                if h is None:
                    hist[line] = {"last": date, "last_author": author, "first": date, "commits": 1}
                else:
                    h["first"] = date
                    h["commits"] += 1
    rows = []
    for f in files:
        if not f.lower().endswith(exts) or f.startswith(skip):
            continue
        p = os.path.join(root, f)
        try:
            size = os.path.getsize(p)
        except OSError:
            continue
        row = {"path": f, "bytes": size}
        if size > 2_000_000:
            row.update({"score": 0, "note": "larger than 2 MB; not read"})
            rows.append(row)
            continue
        txt = read(p)
        heads = []
        in_f = False
        for line in txt.split("\n"):
            if line.strip().startswith(("```", "~~~")):
                in_f = not in_f
                continue
            if not in_f:
                m = HEADING_RE.match(line)
                if m:
                    heads.append("%s %s" % (m.group(1), m.group(2)[:100]))
        checked = len(re.findall(r"^\s*[-*+]\s+\[[xX]\]", txt, re.M))
        unchecked = len(re.findall(r"^\s*[-*+]\s+\[ \]", txt, re.M))
        kwh = sum(1 for h in heads if KW_HEAD.search(h))
        phase_heads = sum(1 for h in heads if PHASE_WORD.search(h))
        base = os.path.basename(f)
        depth = f.count("/")
        score = (3 * min(unchecked, 30) + min(checked, 30) + 4 * min(kwh, 10) + 6 * min(phase_heads, 6)
                 + (8 if KW_FILE.search(base) else 0) + (5 if depth == 0 else 3 if f.startswith("docs/") else
                                                          2 if f.startswith("planning/") else 0))
        if f.startswith(("research/", "design-system/")):
            score = max(0, score - 10)
        title = next((h.split(" ", 1)[1] for h in heads if h.startswith("# ")), heads[0].split(" ", 1)[1]
                     if heads else base)
        h = hist.get(f, {})
        row.update({
            "title": title, "score": score, "checked": checked, "unchecked": unchecked,
            "phase_headings": phase_heads, "headings": heads[:30], "heading_count": len(heads),
            "first_commit": h.get("first"), "last_commit": h.get("last"),
            "last_author": h.get("last_author"), "commits": h.get("commits", 0),
        })
        rows.append(row)
    rows.sort(key=lambda r: (-r.get("score", 0), r["path"]))
    issues = None
    if shutil.which("gh"):
        p = run(["gh", "issue", "list", "--state", "all", "--limit", "200", "--json",
                 "number,title,state,labels,updatedAt"], timeout=25)
        if p is not None and p.returncode == 0:
            try:
                issues = json.loads(p.stdout or "[]")
            except ValueError:
                issues = None
    out = {
        "generated_at": now_iso(), "head": git("rev-parse", "HEAD"), "history_shallow": shallow,
        "read_threshold": 60, "files": rows, "issues": issues,
        "issues_note": None if issues is not None else "gh unavailable or not authenticated",
    }
    dest = os.path.join(root, CONT, "onboarding", "inventory.json")
    write(dest, json.dumps(out, indent=1, ensure_ascii=False) + "\n")
    print("Inventory: %d documents scored, %d above zero; top %d to read in full." % (
        len(rows), sum(1 for r in rows if r.get("score", 0) > 0), min(60, len(rows))))
    for r in rows[:25]:
        print("  %4s  %s  (%s/%s boxes, last %s)" % (r.get("score"), r["path"], r.get("checked"),
                                                   (r.get("checked") or 0) + (r.get("unchecked") or 0),
                                                   (r.get("last_commit") or "?")[:10]))
    print("Wrote %s" % os.path.relpath(dest, root))
    if issues is not None:
        print("GitHub issues: %d" % len(issues))
    if shallow:
        print("Note: shallow clone; first-commit dates may be later than the truth.")
    return 0


def cmd_onboard_apply(args):
    root = repo_root()
    os.chdir(root)
    yes = "--yes" in args
    args = [a for a in args if a != "--yes"]
    if not args:
        die("usage: onboard-apply .continuity/onboarding/proposal.json [--yes]")
    prop = json.loads(read(args[0]))
    plans = prop.get("plans", [])
    pending = [p for p in plans if p.get("decision") not in ("approved", "edited", "rejected")]
    if pending:
        die("%d proposal row(s) have no decision (approved, edited, rejected); nothing applied: %s" % (
            len(pending), ", ".join(p.get("doc", "?") for p in pending[:10])), 2)
    accepted = [p for p in plans if p["decision"] in ("approved", "edited")]
    rejected = [p for p in plans if p["decision"] == "rejected"]
    for p in accepted:
        if not os.path.isfile(os.path.join(root, p["doc"])):
            die("proposal names %s, which does not exist" % p["doc"])
    before = {p["doc"]: blob_hash(os.path.join(root, p["doc"])) for p in plans
              if os.path.isfile(os.path.join(root, p["doc"]))}
    slug_to_id = {}
    existing_ids = set()
    for sp in stub_paths(root):
        sfm, _ = load_doc(sp)
        existing_ids.add(sfm.get("id"))
    for p in accepted:
        pid = p.get("id") or rand_id("pl_")
        while pid in existing_ids:
            pid = rand_id("pl_")
        existing_ids.add(pid)
        slug_to_id[p["slug"]] = pid

    def ref(x):
        if not x:
            return x
        head, _, tail = str(x).partition("/")
        return slug_to_id.get(head, head) + ("/" + tail if tail else "")

    print("Onboarding apply: %d plan(s), %d rejected." % (len(accepted), len(rejected)))
    if not confirm("Write stubs, workstreams and onboarding checkpoints, then commit?", yes):
        return 1
    written = []
    for p in accepted:
        src = p["doc"]
        phases, _ = parse_outline(read(os.path.join(root, src)), stub=False)
        assign_ids(phases, [], [])
        outline = render_outline(phases, [])
        fm = {
            "id": slug_to_id[p["slug"]], "title": p["title"], "kind": p.get("kind", "other"),
            "status": p.get("status", "unknown"), "parent": ref(p.get("parent")),
            "implements": [ref(x) for x in p.get("implements", [])],
            "relates": [ref(x) for x in p.get("relates", [])],
            "superseded_by": ref(p.get("superseded_by")),
            "origin": "onboarded", "body_path": src, "source_commit": git("log", "-1", "--format=%H", "--", src),
            "source_hash": blob_hash(os.path.join(root, src)), "synced_at": now_iso(),
            "done_at_default": p.get("done_at_default", "implemented"),
            "phase_status": p.get("phase_status") or None,
            "outline_hash": text_hash(outline),
        }
        fm = {k: v for k, v in fm.items() if v not in (None, "")}
        path = os.path.join(root, CONT, "plans", p["slug"] + ".md")
        if os.path.exists(path):
            die("%s already exists; onboarding does not overwrite stubs" % os.path.relpath(path, root))
        note = ("Onboarded from %s. %s\n\n" % (src, p.get("reason", ""))).rstrip() + "\n\n"
        write(path, dump_fm(fm) + note + DERIVED_MARK.format(path=src) + "\n" + outline)
        written.append(path)
        ws = p.get("workstream")
        if ws:
            a = [ws["title"], "--plans", ",".join([fm["id"]] + [ref(x).split("/")[0] for x in
                                                                ws.get("extra_plans", [])]),
                 "--status", ws.get("status", "active")]
            if ws.get("next_step"):
                a += ["--next", ws["next_step"]]
            if ws.get("branch"):
                a += ["--branch", ws["branch"]]
            import io
            import contextlib
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf):
                cmd_workstream_new(a)
            wid, wrel = buf.getvalue().split("\n")[:2]
            cid = ulid()
            cfm = {"id": cid, "workstream": wid, "plans": [fm["id"]], "kind": "onboarding", "agent": "onboarding",
                   "created": now_iso()}
            cfm.update(tree_facts(root))
            cfm["dirty_files"] = []
            if isinstance(ws.get("waiting"), dict):
                cfm["waiting"] = ws["waiting"]
            body = ("## Objective\n\n%s\n\n## Completed since last checkpoint\n\n%s\n\n## Evidence\n\n"
                    "Derived from %s at commit %s and git history; not verified by an agent session.\n\n"
                    "## Decisions and rejected alternatives\n\n\n## Open questions and blockers\n\n%s\n\n"
                    "## What to check on return\n\nWhether the document still reflects reality.\n\n"
                    "## Next step\n\n%s\n\n## Resume prompt\n\nRun the pickup skill for %s.\n") % (
                ws.get("objective", p["title"]), ws.get("completed", "See the plan document."), src,
                str(fm.get("source_commit", ""))[:12], ws.get("open", ""),
                ws.get("next_step") or "Review the plan and choose the next item.", wid)
            stamp = _dt.datetime.now(_dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            cpath = os.path.join(root, CONT, "checkpoints", "%s-%s-%s.md" % (
                stamp, os.path.basename(wrel)[:-3][:40], cid[-8:].lower()))
            write(cpath, dump_fm(cfm) + body)
            wpath = os.path.join(root, wrel)
            wfm, wb = load_doc(wpath)
            wfm["latest_checkpoint"] = cid
            last = git("log", "-1", "--format=%cI", "--", src)
            if last:
                d = parse_iso(last)
                if d:
                    wfm["last_activity_at"] = d.astimezone(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            write(wpath, dump_fm(wfm) + wb.lstrip("\n"))
    proj = prop.get("project")
    if isinstance(proj, dict):
        ppath = os.path.join(root, CONT, "project.md")
        pfm = {"title": proj.get("title", ""), "roadmap": slug_to_id.get(proj.get("roadmap"), proj.get("roadmap")),
               "context": proj.get("context", [])}
        pbody = "## Goal\n\n%s\n" % proj.get("goal", "")
        if proj.get("notes"):
            pbody += "\n## Notes\n\n" + "\n".join("- " + n for n in proj["notes"]) + "\n"
        write(ppath, dump_fm(pfm) + pbody)
    rep = ["# Onboarding report", "", "Applied %s from proposal %s." % (now_iso(), args[0]), "",
           "## Registered", ""]
    rep += ["- %s -> %s (%s)" % (p["doc"], slug_to_id[p["slug"]], p["title"]) for p in accepted]
    rep += ["", "## Rejected (not re-proposed)", ""]
    rep += ["- %s: %s" % (p["doc"], p.get("reason", "")) for p in rejected] or ["- none"]
    write(os.path.join(root, CONT, "onboarding", "report.md"), "\n".join(rep) + "\n")
    after = {d: blob_hash(os.path.join(root, d)) for d in before if os.path.isfile(os.path.join(root, d))}
    if after != before:
        die("an original document changed during apply; nothing committed. Inspect git status.", 6)
    hits = scan_files([os.path.join(root, CONT, x) for x in (git("ls-files", "-o", "-m", "--exclude-standard",
                                                                   CONT) or "").replace(CONT + "/", "").split("\n") if x])
    if hits:
        die("possible secrets in onboarding output; nothing committed: %s" % ", ".join(hits), 4)
    run(["git", "add", "-A", "--", CONT], check=True)
    run(["git", "commit", "--only", "-q", "-m", "continuity: onboard", "--", CONT], check=True)
    print("Committed %s (continuity: onboard). Originals unchanged." % (git("rev-parse", "--short", "HEAD")))
    return 0


# ------------------------------------------------------------------ main

COMMANDS = {
    "outline": cmd_outline, "sync-plans": cmd_sync_plans, "workstream-new": cmd_workstream_new,
    "workstream-list": cmd_workstream_list, "checkpoint-new": cmd_checkpoint_new,
    "checkpoint-commit": cmd_checkpoint_commit, "pickup": cmd_pickup, "validate": cmd_validate,
    "secret-scan": cmd_secret_scan, "config": cmd_config, "install": cmd_install, "uninstall": cmd_uninstall,
    "onboard-inventory": cmd_onboard_inventory, "onboard-apply": cmd_onboard_apply, "map": cmd_map,
    "dashboard": cmd_dashboard,
}


def main(argv):
    if len(argv) < 2 or argv[1] not in COMMANDS:
        sys.stdout.write(__doc__)
        return 1
    return COMMANDS[argv[1]](list(argv[2:])) or 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
