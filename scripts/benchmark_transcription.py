#!/usr/bin/env python3
"""
Transcription comparison harness (evaluation only; does NOT touch the site, DB or queue).

Compares speech-to-text options on a few audio clips against human-corrected reference text:
  * local faster-whisper at several model sizes (free; measures speed and RAM)
  * optionally OpenAI gpt-transcribe (PAID; refuses to run without --allow-paid-api)

Scores per run: word error rate, medical-term recall, number/dose accuracy, real-time factor, peak RAM,
and whether segment timestamps (needed for the WebVTT captions) are available.

Usage:
  python3 scripts/benchmark_transcription.py --self-test
  python3 scripts/benchmark_transcription.py --audio clip1.wav --reference clip1.txt \
      --models base,small,medium --terms content-pipeline/standards/medical-terminology-standards.json \
      --out scripts/output/benchmark
  # paid (needs approval first; prints an estimate and asks you to pass the flag):
  OPENAI_API_KEY=... python3 scripts/benchmark_transcription.py --audio clip1.wav --reference clip1.txt \
      --models none --gpt-transcribe --allow-paid-api
"""
import argparse
import json
import os
import re
import sys
import time

GPT_TRANSCRIBE_PRICE_PER_MIN = 0.0045  # USD, per OpenAI model page; re-check before running


# ---------------------------------------------------------------- scoring (pure functions)
def normalize(text):
    text = text.lower()
    text = re.sub(r"(\d),(\d)", r"\1\2", text)          # 1,000 -> 1000
    text = re.sub(r"[^\w\s.\-/%]", " ", text)           # keep digits, decimals, hyphens (CA 19-9, 5-FU)
    text = re.sub(r"(?<!\d)\.|\.(?!\d)", " ", text)     # drop sentence periods, keep 12.5
    return text.split()


def edit_distance(a, b):
    prev = list(range(len(b) + 1))
    for i, x in enumerate(a, 1):
        cur = [i]
        for j, y in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (x != y)))
        prev = cur
    return prev[-1]


def wer(reference, hypothesis):
    ref, hyp = normalize(reference), normalize(hypothesis)
    return edit_distance(ref, hyp) / max(1, len(ref))


def term_recall(reference, hypothesis, terms):
    """Of the glossary terms that occur in the reference, how many appear in the hypothesis?"""
    ref_n, hyp_n = " ".join(normalize(reference)), " ".join(normalize(hypothesis))
    present = [t for t in terms if " ".join(normalize(t)) and " ".join(normalize(t)) in ref_n]
    hit = [t for t in present if " ".join(normalize(t)) in hyp_n]
    missed = [t for t in present if t not in hit]
    return {"terms_in_reference": len(present), "recalled": len(hit), "recall": (len(hit) / len(present)) if present else None, "missed": missed}


def number_accuracy(reference, hypothesis):
    nums = lambda t: re.findall(r"\d[\d,]*(?:\.\d+)?", t)
    ref = [n.replace(",", "") for n in nums(reference)]
    hyp = [n.replace(",", "") for n in nums(hypothesis)]
    pool = list(hyp)
    hit = 0
    for n in ref:
        if n in pool:
            pool.remove(n)
            hit += 1
    return {"numbers_in_reference": len(ref), "correct": hit, "accuracy": (hit / len(ref)) if ref else None}


def load_terms(path):
    """Glossary from a plain text file (one term per line) or the site's standards/entity JSON files."""
    if not path:
        return []
    raw = open(path, encoding="utf-8").read()
    if path.endswith(".json"):
        data = json.loads(raw)
        found = []

        def walk(node):
            if isinstance(node, dict):
                for k, v in node.items():
                    if isinstance(k, str) and len(k) > 2 and not k.islower():
                        found.append(k)
                    walk(v)
            elif isinstance(node, list):
                for v in node:
                    walk(v)
            elif isinstance(node, str) and 2 < len(node) < 60:
                found.append(node)
        walk(data)
        return sorted(set(found))
    return [l.strip() for l in raw.splitlines() if l.strip()]


def self_test():
    assert wer("the cat sat", "the cat sat") == 0
    assert abs(wer("one two three four", "one two tree four") - 0.25) < 1e-9
    assert wer("CA 19-9 was 38 U/mL.", "CA 19-9 was 38 U/mL") == 0
    r = term_recall("Take 5-FU and gemcitabine with BRCA1 testing", "take 5 fu and gem citabine with BRCA1 testing", ["5-FU", "gemcitabine", "BRCA1"])
    assert r["terms_in_reference"] == 3 and r["recalled"] == 1 and set(r["missed"]) == {"5-FU", "gemcitabine"}, r
    n = number_accuracy("5000 IU and 1,000 mg at 12.5 percent", "500 IU and 1000 mg at 12.5 percent")
    assert n["numbers_in_reference"] == 3 and n["correct"] == 2, n
    print("self-test ok")


# ---------------------------------------------------------------- engines
def peak_rss_mb():
    try:
        import resource
        r = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        return round(r / (1024 * 1024) if sys.platform == "darwin" else r / 1024, 1)
    except Exception:
        return None


def audio_seconds(path):
    import subprocess
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=nw=1:nk=1", path], capture_output=True, text=True)
    return float(out.stdout.strip())


def run_faster_whisper(audio, model_name, bias_terms, language, out_dir):
    from faster_whisper import WhisperModel
    t0 = time.time()
    model = WhisperModel(model_name, device="cpu", compute_type="int8")
    load_s = time.time() - t0
    kwargs = {"language": language, "vad_filter": True}
    if bias_terms:
        kwargs["initial_prompt"] = "Medical terms: " + ", ".join(bias_terms[:80]) + "."
    t1 = time.time()
    segments = list(model.transcribe(audio, **kwargs)[0])
    run_s = time.time() - t1
    text = " ".join(s.text.strip() for s in segments)
    vtt = os.path.join(out_dir, "%s_%s.vtt" % (os.path.splitext(os.path.basename(audio))[0], model_name))
    with open(vtt, "w", encoding="utf-8") as f:
        f.write("WEBVTT\n\n")
        for s in segments:
            f.write("%s --> %s\n%s\n\n" % (ts(s.start), ts(s.end), s.text.strip()))
    return {"text": text, "load_seconds": round(load_s, 1), "run_seconds": round(run_s, 1), "timestamps": True, "vtt": vtt, "cost_usd": 0.0}


def ts(sec):
    return "%02d:%02d:%06.3f" % (int(sec // 3600), int(sec % 3600 // 60), sec % 60)


def run_gpt_transcribe(audio, language, bias_terms):
    import urllib.request, uuid
    key = os.environ.get("OPENAI_API_KEY")
    if not key:
        raise SystemExit("OPENAI_API_KEY not set")
    if os.path.getsize(audio) > 25 * 1024 * 1024:
        raise SystemExit("File over the 25 MB API limit; split it first (e.g. 10-minute mp3 chunks).")
    fields = {"model": "gpt-transcribe"}
    if bias_terms:
        fields["keywords"] = "\n".join(t for t in bias_terms[:100] if not re.search(r"[<>\r\n]", t))
    boundary = uuid.uuid4().hex
    body = b""
    for k, v in fields.items():
        body += ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (boundary, k, v)).encode()
    body += ("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: application/octet-stream\r\n\r\n" % (boundary, os.path.basename(audio))).encode()
    body += open(audio, "rb").read() + ("\r\n--%s--\r\n" % boundary).encode()
    req = urllib.request.Request("https://api.openai.com/v1/audio/transcriptions", data=body, headers={"Authorization": "Bearer " + key, "Content-Type": "multipart/form-data; boundary=" + boundary})
    t0 = time.time()
    data = json.loads(urllib.request.urlopen(req, timeout=600).read())
    secs = audio_seconds(audio)
    return {"text": data.get("text", ""), "load_seconds": 0, "run_seconds": round(time.time() - t0, 1), "timestamps": False,
            "vtt": None, "cost_usd": round(secs / 60 * GPT_TRANSCRIBE_PRICE_PER_MIN, 4)}


# ---------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--self-test", action="store_true")
    ap.add_argument("--audio", action="append", default=[])
    ap.add_argument("--reference", action="append", default=[], help="reference .txt, same order as --audio")
    ap.add_argument("--models", default="base,small,medium", help="comma list of faster-whisper sizes, or 'none'")
    ap.add_argument("--terms", help="glossary: .txt (one per line) or standards/entity .json")
    ap.add_argument("--bias", action="store_true", help="also run each model with the glossary as an initial prompt")
    ap.add_argument("--gpt-transcribe", action="store_true")
    ap.add_argument("--allow-paid-api", action="store_true", help="required for --gpt-transcribe")
    ap.add_argument("--language", default="en")
    ap.add_argument("--out", default="scripts/output/benchmark")
    a = ap.parse_args()
    if a.self_test:
        self_test()
        return 0
    if not a.audio or len(a.audio) != len(a.reference):
        ap.error("give one --reference per --audio")
    terms = load_terms(a.terms)
    os.makedirs(a.out, exist_ok=True)
    plan = []
    if a.models != "none":
        for m in a.models.split(","):
            plan.append(("faster-whisper:" + m.strip(), False))
            if a.bias and terms:
                plan.append(("faster-whisper:" + m.strip() + "+glossary", True))
    if a.gpt_transcribe:
        total_min = sum(audio_seconds(x) for x in a.audio) / 60
        est = total_min * GPT_TRANSCRIBE_PRICE_PER_MIN
        print("gpt-transcribe estimate: %.1f audio minutes = about $%.3f" % (total_min, est))
        if not a.allow_paid_api:
            print("Not running paid API without --allow-paid-api (skipping).")
        else:
            plan.append(("openai:gpt-transcribe", bool(terms)))
    rows = []
    for audio, ref_path in zip(a.audio, a.reference):
        ref = open(ref_path, encoding="utf-8").read()
        dur = audio_seconds(audio)
        for name, bias in plan:
            print("running", name, os.path.basename(audio), flush=True)
            if name.startswith("faster-whisper"):
                res = run_faster_whisper(audio, name.split(":")[1].split("+")[0], terms if bias else None, a.language, a.out)
            else:
                res = run_gpt_transcribe(audio, a.language, terms if bias else None)
            row = {"audio": os.path.basename(audio), "engine": name, "audio_seconds": round(dur, 1),
                   "wer": round(wer(ref, res["text"]), 4), "terms": term_recall(ref, res["text"], terms),
                   "numbers": number_accuracy(ref, res["text"]),
                   "real_time_factor": round(res["run_seconds"] / dur, 3) if dur else None,
                   "load_seconds": res["load_seconds"], "peak_rss_mb": peak_rss_mb(), "timestamps": res["timestamps"], "cost_usd": res["cost_usd"]}
            rows.append(row)
            with open(os.path.join(a.out, "results.json"), "w") as f:
                json.dump(rows, f, indent=2)
    print("\n| clip | engine | WER | term recall | number acc | RTF | peak RAM MB | timestamps | cost |")
    print("|---|---|---|---|---|---|---|---|---|")
    pct = lambda x: "n/a" if x is None else "%.1f%%" % (x * 100)
    for r in rows:
        print("| %s | %s | %s | %s | %s | %s | %s | %s | $%.4f |" % (r["audio"], r["engine"], pct(r["wer"]), pct(r["terms"]["recall"]), pct(r["numbers"]["accuracy"]), r["real_time_factor"], r["peak_rss_mb"], "yes" if r["timestamps"] else "NO", r["cost_usd"]))
    return 0


if __name__ == "__main__":
    sys.exit(main())
