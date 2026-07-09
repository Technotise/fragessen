# segments_pipeline.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
segments_pipeline.py (single final output + clean logging)

Final target:
- <package>/segments.json is the ONLY final output.
- Temp artifacts in <package>/.tmp_pipeline (deleted unless --keep_temp)

Stages:
1) Deterministic: segments.py -> segments_validate.py
   - accept + score >= threshold => finalize
   - need_spans/broken => DocAI hint stage (if enabled/available)
2) DocAI hints: segments_document_ai.py -> reconstruct spans deterministically on PDF OFFSET SPACE -> validate
   - accept => finalize
   - else: choose best by (recommendation rank, span coverage, score)

Critical:
- DocAI does NOT generate offsets. It only provides start_page + hint_anchor.
- Offsets are reconstructed deterministically on PDF text (extract_pages_offset + top_key_regex_line_start).
- Anti-TOC hard gate:
  - we estimate content_start_page from PDF text and pass it to DocAI.
  - additionally, reconstruction clamps searches to be >= content_start_page where applicable.
"""

import argparse
import csv
import json
import os
import re
import shutil
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Tuple, Optional, List

try:
    from dotenv import load_dotenv
except Exception:
    load_dotenv = None

from segments_utils import (
    extract_pages_offset,
    collect_text_for_span,
    has_full_span,
    find_top_start_from_hint,
)

# ----------------------------
# IO helpers
# ----------------------------

@dataclass
class RunResult:
    returncode: int
    stdout: str


def _run(cmd: List[str], env: Optional[Dict[str, str]] = None, raise_on_error: bool = True) -> RunResult:
    p = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        env=env or os.environ.copy(),
    )
    out = (p.stdout or "").strip()
    rr = RunResult(returncode=int(p.returncode), stdout=out)
    if raise_on_error and rr.returncode != 0:
        raise SystemExit(f"Command failed ({rr.returncode}): {' '.join(cmd)}\n\n{rr.stdout}")
    return rr


def _read_json(p: Path) -> Dict[str, Any]:
    return json.loads(p.read_text(encoding="utf-8"))


def _write_json(p: Path, obj: Dict[str, Any]) -> None:
    p.write_text(json.dumps(obj, ensure_ascii=False, indent=2), encoding="utf-8")


def _safe_rmtree(p: Path) -> None:
    try:
        if p.exists():
            shutil.rmtree(p)
    except Exception:
        pass


def _atomic_write_json(path: Path, obj: Dict[str, Any]) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    _write_json(tmp, obj)
    tmp.replace(path)


def _ensure_parent_dir(p: Path) -> None:
    if p.parent and not p.parent.exists():
        p.parent.mkdir(parents=True, exist_ok=True)


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _short_tail(s: str, max_chars: int = 4000) -> str:
    if not s:
        return ""
    s = s.strip()
    if len(s) <= max_chars:
        return s
    return s[-max_chars:]


def _csv_safe(s: Any) -> str:
    if s is None:
        return ""
    if isinstance(s, (int, float, bool)):
        return str(s)
    return str(s).replace("\r", "\\r").replace("\n", "\\n")


# ----------------------------
# Env / config
# ----------------------------

def _load_env() -> None:
    if load_dotenv is None:
        return
    env_path = Path(__file__).resolve().parent / ".env"
    if env_path.exists():
        load_dotenv(env_path)


def _env_int(name: str, default: int) -> int:
    v = (os.getenv(name) or "").strip()
    if not v:
        return int(default)
    try:
        return int(v)
    except Exception:
        return int(default)


def _env_float(name: str, default: float) -> float:
    v = (os.getenv(name) or "").strip()
    if not v:
        return float(default)
    try:
        return float(v)
    except Exception:
        return float(default)


def _env_bool(name: str, default: bool = False) -> bool:
    v = (os.getenv(name) or "").strip().lower()
    if not v:
        return bool(default)
    return v in ("1", "true", "yes", "y", "on")


# ----------------------------
# Content start detection (anti-TOC)
# ----------------------------

_RE_PAGE_NUM_LINE = re.compile(r"(?m)^\s*[-–—]\s*\d{1,3}\s*[-–—]\s*$")
_RE_PAGE_NUM_INLINE = re.compile(r"(?i)\bseite\s+\d{1,3}\b")


def detect_content_start_page(pages: List[str]) -> int:
    """
    Heuristic: find first page that looks like running minutes text.
    We prefer pages with a standalone "- 4 -" like footer/header.
    Fallback: first page containing "Seite <n>".
    If nothing matches: return 1.
    """
    if not pages:
        return 1
    for i, t in enumerate(pages, start=1):
        if not t:
            continue
        if _RE_PAGE_NUM_LINE.search(t):
            return i
    for i, t in enumerate(pages, start=1):
        if not t:
            continue
        if _RE_PAGE_NUM_INLINE.search(t):
            return i
    return 1


# ----------------------------
# Deterministic text enrichment (ONLY WITH SPANS)
# ----------------------------

def enrich_text_full_in_segments(doc: Dict[str, Any], pdf_path: Path) -> Dict[str, Any]:
    """Fill missing/empty text_full deterministically from PDF only when spans exist."""
    segs = doc.get("segments")
    if not isinstance(segs, list) or not segs:
        return doc

    pages = extract_pages_offset(pdf_path)

    for s in segs:
        if not isinstance(s, dict):
            continue
        if not has_full_span(s):
            continue

        cur = s.get("text_full")
        if isinstance(cur, str) and cur.strip():
            continue

        sp1 = int(s["start_page"])
        sc = int(s["start_char"])
        ep1 = int(s["end_page"])
        ec = int(s["end_char"])

        s["text_full"] = collect_text_for_span(pages, sp1, sc, ep1, ec)

    return doc


# ----------------------------
# Validation
# ----------------------------

def _find_validator_script() -> Path:
    here = Path(__file__).resolve().parent
    candidates = [
        here / "segments_validate.py",
        here / "validate.py",
        here / "validate_segments.py",
    ]
    for p in candidates:
        if p.exists():
            return p
    raise SystemExit(f"Validator script missing: tried {[str(x) for x in candidates]}")


def _validator_supports_patch_flag(script_path: Path) -> bool:
    try:
        txt = script_path.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        return False
    return ("--patch_segments" in txt)


def validate_with_script(
    pdf: Path,
    segments_json: Path,
    out_report: Path,
    min_top_chars: int,
    marker_near_start_chars: int,
    leakage_early_window: int,
    threshold: float,
    patch_segments: bool,
) -> Tuple[float, Dict[str, Any]]:
    script = _find_validator_script()
    supports_patch = _validator_supports_patch_flag(script)

    cmd = [
        sys.executable, str(script),
        "--pdf", str(pdf),
        "--segments", str(segments_json),
        "--out", str(out_report),
        "--min_top_chars", str(int(min_top_chars)),
        "--marker_near_start_chars", str(int(marker_near_start_chars)),
        "--leakage_early_window", str(int(leakage_early_window)),
        "--threshold", str(float(threshold)),
    ]

    if supports_patch and patch_segments:
        cmd.append("--patch_segments")

    rr = _run(cmd, raise_on_error=True)
    rep = _read_json(out_report)
    score = float(rep.get("score") or 0.0)
    rep.setdefault("_validator_stdout_tail", _short_tail(rr.stdout, 2000))
    return score, rep


def _rep_recommendation(rep: Dict[str, Any]) -> str:
    r = (rep.get("recommendation") or "").strip().lower()
    if r in ("accept", "need_spans", "broken"):
        return r
    if rep.get("ok") is True:
        return "accept"
    counts = rep.get("counts") or {}
    severe = int(counts.get("issues_severe") or 0)
    if severe > 0:
        return "broken"
    return "need_spans"


def _rep_is_broken(rep: Dict[str, Any]) -> bool:
    if _rep_recommendation(rep) == "broken":
        return True
    counts = rep.get("counts") or {}
    severe = int(counts.get("issues_severe") or 0)
    return severe > 0


def _tops_with_span(rep: Dict[str, Any]) -> int:
    counts = rep.get("counts") or {}
    return int(counts.get("tops_with_span") or 0)


def _tops_total_meta(rep: Dict[str, Any]) -> int:
    counts = rep.get("counts") or {}
    return int(counts.get("tops_total_meta") or 0)


def _span_coverage(rep: Dict[str, Any]) -> float:
    tot = _tops_total_meta(rep)
    if tot <= 0:
        return 0.0
    return _tops_with_span(rep) / float(tot)


# ----------------------------
# Stage: DocAI hints + reconstruction
# ----------------------------

def _find_docai_dump_near(out_dir: Path) -> Optional[Path]:
    for p in [
        out_dir / "docai_pages.json",
        out_dir / "pages.json",
        out_dir / "docai_raw.json",
        out_dir / "ocr_pages.json",
        out_dir.parent / "docai_pages.json",
    ]:
        if p.exists():
            return p
    return None


def run_docai_hints(
    pdf_path: Path,
    agenda_path: Path,
    out_dir: Path,
    timeout_s: int,
    content_start_page: int,
    force_full_pdf: bool = True,
) -> Tuple[Path, str]:
    """
    Runs segments_document_ai.py to produce a hint-only segments.json.
    Returns (hints_json_path, stdout_tail)
    """
    script = Path(__file__).resolve().parent / "segments_document_ai.py"
    if not script.exists():
        raise SystemExit(f"DocAI hints script missing: {script}")

    out_dir.mkdir(parents=True, exist_ok=True)
    docai_out_dir = out_dir / ("docai_full" if force_full_pdf else "docai_run")
    if docai_out_dir.exists():
        _safe_rmtree(docai_out_dir)
    docai_out_dir.mkdir(parents=True, exist_ok=True)

    env = os.environ.copy()

    cmd = [
        sys.executable, str(script),
        "--pdf", str(pdf_path),
        "--agenda", str(agenda_path),
        "--out", str(docai_out_dir),
        "--timeout_s", str(timeout_s),
        "--content_start_page", str(int(max(1, content_start_page))),
    ]
    if force_full_pdf:
        cmd.append("--force_full_pdf")

    dump = _find_docai_dump_near(out_dir)
    if dump is not None:
        cmd += ["--docai_pages_json", str(dump)]  # optional arg if you have it in your local fork

    rr = _run(cmd, env=env, raise_on_error=True)

    produced = docai_out_dir / "segments.json"
    if not produced.exists():
        raise SystemExit("segments_document_ai.py did not produce segments.json")

    target = out_dir / ("docai_full_hints.json" if force_full_pdf else "docai_hints.json")
    shutil.copyfile(str(produced), str(target))
    return target, _short_tail(rr.stdout, 4000)


# ----------------------------
# Derivation helpers: always emit vorwort/nachwort/anhang
# ----------------------------

_RE_APPENDIX = re.compile(
    r"(?mi)^\s*(anlage|anlagen|anhang|protokollunterlagen|unterlagen|anlagenverzeichnis)\b"
)


def _detect_appendix_start_page(pages: List[str], start_search_page1: int) -> Optional[int]:
    if not pages:
        return None
    n = len(pages)
    p0 = max(1, min(int(start_search_page1), n))
    for p1 in range(p0, n + 1):
        t = pages[p1 - 1] or ""
        if _RE_APPENDIX.search(t):
            return p1
    return None


def _ensure_base_meta(doc: Dict[str, Any]) -> Dict[str, Any]:
    meta = doc.get("meta")
    if not isinstance(meta, dict):
        meta = {}
        doc["meta"] = meta
    return meta


def reconstruct_spans_from_hints(hints_doc: Dict[str, Any], pdf_path: Path, *, content_start_page: int) -> Dict[str, Any]:
    pages = extract_pages_offset(pdf_path)
    pages_total = len(pages)
    if pages_total <= 0:
        return hints_doc

    segs_in = hints_doc.get("segments")
    if not isinstance(segs_in, list) or not segs_in:
        hints_doc["segments"] = []
        _ensure_base_meta(hints_doc)["pages_total"] = pages_total
        return hints_doc

    meta = _ensure_base_meta(hints_doc)

    content_end_page = pages_total
    content_end_char = len(pages[-1]) if pages else 0

    tops: List[Dict[str, Any]] = []
    provided_vorwort: Optional[Dict[str, Any]] = None
    provided_nachwort: Optional[Dict[str, Any]] = None
    provided_anhang: Optional[Dict[str, Any]] = None

    for s in segs_in:
        if not isinstance(s, dict):
            continue
        st = (s.get("type") or "").strip().lower()
        if st == "top":
            tops.append(s)
        elif st == "vorwort" and provided_vorwort is None:
            provided_vorwort = s
        elif st == "nachwort" and provided_nachwort is None:
            provided_nachwort = s
        elif st == "anhang" and provided_anhang is None:
            provided_anhang = s

    csp = int(max(1, content_start_page))

    # Reconstruct TOP starts (page1,char)
    top_starts: List[Optional[Tuple[int, int]]] = [None] * len(tops)

    for i, s in enumerate(tops):
        hint_p1 = s.get("start_page")
        key = (s.get("top_key") or "").strip()
        anchor = (s.get("hint_anchor") or "").strip() if isinstance(s.get("hint_anchor"), str) else ""

        if not key or not isinstance(hint_p1, int) or hint_p1 <= 0:
            top_starts[i] = None
            continue

        # Hard clamp the search start away from TOC region
        hint_start = max(int(hint_p1), csp)

        pos = find_top_start_from_hint(
            pages,
            top_key=key,
            hint_start_page1=hint_start,
            hint_anchor=anchor,
            window_pages=2,
        )
        top_starts[i] = pos

    first_found_idx = next((i for i, p in enumerate(top_starts) if p is not None), None)
    last_found_idx = next((i for i in range(len(top_starts) - 1, -1, -1) if top_starts[i] is not None), None)

    if first_found_idx is None or last_found_idx is None:
        out_segments: List[Dict[str, Any]] = []
        for s in tops:
            s["start_char"] = None
            s["end_page"] = None
            s["end_char"] = None
            s["text_full"] = ""
            s["match_method"] = "missing_after_docai_hint"
            if not isinstance(s.get("confidence"), (int, float)):
                s["confidence"] = 0.2
            out_segments.append(s)

        out_segments.insert(0, {
            "type": "vorwort",
            "title": (provided_vorwort.get("title") if isinstance(provided_vorwort, dict) else "Vorwort") or "Vorwort",
            "start_page": None,
            "start_char": None,
            "end_page": None,
            "end_char": None,
            "hint_anchor": None,
            "evidence_quote": None,
            "text_full": "",
            "confidence": 0.2,
            "match_method": "derived_no_tops_found",
        })
        out_segments.append({
            "type": "nachwort",
            "title": (provided_nachwort.get("title") if isinstance(provided_nachwort, dict) else "Nachwort") or "Nachwort",
            "start_page": None,
            "start_char": None,
            "end_page": None,
            "end_char": None,
            "hint_anchor": None,
            "evidence_quote": None,
            "text_full": "",
            "confidence": 0.2,
            "match_method": "derived_no_tops_found",
        })

        hints_doc["segments"] = out_segments
        meta["pages_total"] = pages_total
        meta["emit_text_full"] = True
        meta["reconstructed_spans"] = False
        meta["tops_total"] = int(meta.get("tops_total") or len(tops))
        meta["tops_found"] = 0
        meta["content_start_page"] = csp
        return hints_doc

    first_top_p1, first_top_sc = top_starts[first_found_idx]  # type: ignore[misc]
    last_top_p1, _ = top_starts[last_found_idx]  # type: ignore[misc]

    appendix_start_page = _detect_appendix_start_page(pages, start_search_page1=int(last_top_p1))
    if appendix_start_page is not None and appendix_start_page < int(last_top_p1):
        appendix_start_page = None

    def _next_found_top_start(i: int) -> Optional[Tuple[int, int]]:
        for j in range(i + 1, len(top_starts)):
            if top_starts[j] is not None:
                return top_starts[j]
        return None

    out_tops: List[Dict[str, Any]] = []
    for i, s in enumerate(tops):
        pos = top_starts[i]
        if pos is None:
            s["start_char"] = None
            s["end_page"] = None
            s["end_char"] = None
            s["text_full"] = ""
            s["match_method"] = "missing_after_docai_hint"
            if not isinstance(s.get("confidence"), (int, float)):
                s["confidence"] = 0.2
            out_tops.append(s)
            continue

        sp1, sc = int(pos[0]), int(pos[1])

        nxt = _next_found_top_start(i)
        if nxt is not None:
            ep1, ec = int(nxt[0]), int(nxt[1])
        else:
            if appendix_start_page is not None:
                ep1, ec = int(appendix_start_page), 0
            else:
                ep1, ec = int(content_end_page), int(content_end_char)

        if (ep1 < sp1) or (ep1 == sp1 and ec < sc):
            ep1, ec = sp1, sc

        s["start_page"] = sp1
        s["start_char"] = sc
        s["end_page"] = ep1
        s["end_char"] = ec

        s["match_method"] = "reconstruct_from_docai_anchor"
        s["confidence"] = float(s.get("confidence")) if isinstance(s.get("confidence"), (int, float)) else 0.9
        s["text_full"] = collect_text_for_span(pages, sp1, sc, ep1, ec)
        out_tops.append(s)

    vorwort_title = (provided_vorwort.get("title") if isinstance(provided_vorwort, dict) else None) or "Protokollkopf"
    vorwort_seg: Dict[str, Any] = {
        "type": "vorwort",
        "title": str(vorwort_title),
        "start_page": int(csp),
        "start_char": 0,
        "end_page": int(first_top_p1),
        "end_char": int(first_top_sc),
        "hint_anchor": (provided_vorwort.get("hint_anchor") if isinstance(provided_vorwort, dict) else None),
        "evidence_quote": (provided_vorwort.get("evidence_quote") if isinstance(provided_vorwort, dict) else None),
        "confidence": float(provided_vorwort.get("confidence")) if isinstance(provided_vorwort, dict) and isinstance(provided_vorwort.get("confidence"), (int, float)) else 0.9,
        "match_method": "derived_from_first_top",
        "text_full": collect_text_for_span(pages, int(csp), 0, int(first_top_p1), int(first_top_sc)),
    }

    last_top_seg = None
    for s in reversed(out_tops):
        if isinstance(s, dict) and has_full_span(s):
            last_top_seg = s
            break

    if last_top_seg is not None:
        last_end_page = int(last_top_seg["end_page"])
        last_end_char = int(last_top_seg["end_char"])
    else:
        last_end_page = int(first_top_p1)
        last_end_char = int(first_top_sc)

    if appendix_start_page is not None:
        nachwort_end_page, nachwort_end_char = int(appendix_start_page), 0
    else:
        nachwort_end_page, nachwort_end_char = int(content_end_page), int(content_end_char)

    nachwort_title = (provided_nachwort.get("title") if isinstance(provided_nachwort, dict) else None) or "Schluss der Sitzung"
    nachwort_seg: Dict[str, Any] = {
        "type": "nachwort",
        "title": str(nachwort_title),
        "start_page": int(last_end_page),
        "start_char": int(last_end_char),
        "end_page": int(nachwort_end_page),
        "end_char": int(nachwort_end_char),
        "hint_anchor": (provided_nachwort.get("hint_anchor") if isinstance(provided_nachwort, dict) else None),
        "evidence_quote": (provided_nachwort.get("evidence_quote") if isinstance(provided_nachwort, dict) else None),
        "confidence": float(provided_nachwort.get("confidence")) if isinstance(provided_nachwort, dict) and isinstance(provided_nachwort.get("confidence"), (int, float)) else 0.9,
        "match_method": "derived_after_last_top",
        "text_full": collect_text_for_span(pages, int(last_end_page), int(last_end_char), int(nachwort_end_page), int(nachwort_end_char)),
    }

    anhang_seg: Optional[Dict[str, Any]] = None
    if appendix_start_page is not None:
        anhang_title = (provided_anhang.get("title") if isinstance(provided_anhang, dict) else None) or "Anhang"
        anhang_seg = {
            "type": "anhang",
            "title": str(anhang_title),
            "start_page": int(appendix_start_page),
            "start_char": 0,
            "end_page": int(content_end_page),
            "end_char": int(content_end_char),
            "hint_anchor": (provided_anhang.get("hint_anchor") if isinstance(provided_anhang, dict) else None),
            "evidence_quote": (provided_anhang.get("evidence_quote") if isinstance(provided_anhang, dict) else None),
            "confidence": float(provided_anhang.get("confidence")) if isinstance(provided_anhang, dict) and isinstance(provided_anhang.get("confidence"), (int, float)) else 0.9,
            "match_method": "derived_appendix_marker",
            "text_full": collect_text_for_span(pages, int(appendix_start_page), 0, int(content_end_page), int(content_end_char)),
        }

    out_segments: List[Dict[str, Any]] = [vorwort_seg] + out_tops + [nachwort_seg]
    if anhang_seg is not None:
        out_segments.append(anhang_seg)

    meta["pages_total"] = pages_total
    meta["emit_text_full"] = True
    meta["reconstructed_spans"] = True
    meta["appendix_start_page"] = appendix_start_page
    meta["content_start_page"] = csp
    meta["tops_total"] = int(meta.get("tops_total") or len(tops))
    meta["tops_found"] = int(sum(1 for s in out_tops if isinstance(s, dict) and s.get("type") == "top" and has_full_span(s)))

    hints_doc["segments"] = out_segments
    return hints_doc


# ----------------------------
# Logging
# ----------------------------

def _log_fields() -> List[str]:
    return [
        "ts_utc",
        "package_dir",
        "pdf",
        "agenda",
        "stage_final",
        "used_docai",
        "pipeline_ok",
        "threshold",
        "score_final",
        "recommendation_final",
        "span_coverage_final",
        "tops_total_meta",
        "tops_with_span",
        "det_score",
        "det_rec",
        "det_span_cov",
        "docai_score",
        "docai_rec",
        "docai_span_cov",
        "error",
        "error_stage",
        "error_detail",
        "segments_final",
    ]


def _append_csv_row(csv_path: Path, row: Dict[str, Any]) -> None:
    _ensure_parent_dir(csv_path)
    new_file = not csv_path.exists() or csv_path.stat().st_size == 0
    with csv_path.open("a", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=_log_fields(), delimiter=";")
        if new_file:
            w.writeheader()
        out_row = {k: _csv_safe(row.get(k, "")) for k in _log_fields()}
        w.writerow(out_row)


def _append_jsonl(jsonl_path: Path, obj: Dict[str, Any]) -> None:
    _ensure_parent_dir(jsonl_path)
    with jsonl_path.open("a", encoding="utf-8") as f:
        f.write(json.dumps(obj, ensure_ascii=False) + "\n")


# ----------------------------
# Main
# ----------------------------

def main() -> None:
    _load_env()

    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--agenda", required=True)
    ap.add_argument("--segmenter", required=True, help="Path to segments.py")
    ap.add_argument("--keep_temp", action="store_true")

    ap.add_argument("--threshold", type=float, default=_env_float("PIPELINE_SCORE_THRESHOLD", 90.0))

    # Validation knobs
    ap.add_argument("--min_top_chars", type=int, default=_env_int("VALIDATE_MIN_TOP_CHARS", 20))
    ap.add_argument("--marker_near_start_chars", type=int, default=_env_int("VALIDATE_MARKER_NEAR_START_CHARS", 600))
    ap.add_argument("--leakage_early_window", type=int, default=_env_int("VALIDATE_LEAKAGE_EARLY_WINDOW", 1400))

    # DocAI knobs
    ap.add_argument(
        "--use_docai",
        action="store_true",
        default=_env_bool("PIPELINE_USE_DOCAI", True),
        help="Enable DocAI hint stage if needed/available.",
    )
    ap.add_argument("--docai_timeout_s", type=int, default=_env_int("DOCAI_TIMEOUT_S", 180))
    ap.add_argument(
        "--docai_force_full_pdf",
        action="store_true",
        default=_env_bool("DOCAI_FORCE_FULL_PDF", True),
        help="Prefer DocAI full-pdf mode (recommended).",
    )

    ap.add_argument(
        "--patch_in_validator",
        action="store_true",
        default=_env_bool("VALIDATE_PATCH_SEGMENTS", False),
        help="If validator supports it, let validator fill missing text_full for spanned segments.",
    )

    ap.add_argument("--log_csv", default=os.getenv("PIPELINE_LOG_CSV", "").strip(),
                    help="Append a semicolon-separated CSV row per run.")
    ap.add_argument("--log_jsonl", default=os.getenv("PIPELINE_LOG_JSONL", "").strip(),
                    help="Append a JSON object per run (JSONL).")

    args = ap.parse_args()

    pdf_path = Path(args.pdf)
    agenda_path = Path(args.agenda)
    segmenter_path = Path(args.segmenter)
    threshold = float(args.threshold)

    package_dir = pdf_path.parent
    temp_dir = package_dir / ".tmp_pipeline"
    _safe_rmtree(temp_dir)
    temp_dir.mkdir(parents=True, exist_ok=True)

    used_docai = False
    docai_stdout_tail = ""

    best_path: Optional[Path] = None
    best_score: float = -1.0
    best_rep: Dict[str, Any] = {}
    best_stage: str = "none"

    det_score: Optional[float] = None
    det_rec: str = ""
    det_cov: Optional[float] = None
    docai_score: Optional[float] = None
    docai_rec: str = ""
    docai_cov: Optional[float] = None

    error: str = ""
    error_stage: str = ""
    error_detail: str = ""

    log_csv_path = Path(args.log_csv) if args.log_csv else None
    log_jsonl_path = Path(args.log_jsonl) if args.log_jsonl else None

    final_path: Optional[Path] = None
    rec_best: str = ""
    pipeline_ok: bool = False
    span_cov_final: float = 0.0

    def _emit_log(final_payload: Dict[str, Any]) -> None:
        try:
            if log_csv_path:
                _append_csv_row(log_csv_path, final_payload)
        except Exception:
            pass
        try:
            if log_jsonl_path:
                _append_jsonl(log_jsonl_path, final_payload)
        except Exception:
            pass

    def _validate(seg_path: Path, report_name: str) -> Tuple[float, Dict[str, Any]]:
        rep_path = temp_dir / report_name
        score, rep = validate_with_script(
            pdf=pdf_path,
            segments_json=seg_path,
            out_report=rep_path,
            min_top_chars=int(args.min_top_chars),
            marker_near_start_chars=int(args.marker_near_start_chars),
            leakage_early_window=int(args.leakage_early_window),
            threshold=threshold,
            patch_segments=bool(args.patch_in_validator),
        )
        return score, rep

    def _consider(seg_path: Path, score: float, rep: Dict[str, Any], stage: str) -> None:
        nonlocal best_path, best_score, best_rep, best_stage

        if best_path is None:
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage
            return

        cur_broken = _rep_is_broken(rep)
        best_broken = _rep_is_broken(best_rep)
        if best_broken and not cur_broken:
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage
            return
        if cur_broken and not best_broken:
            return

        cur_rec = _rep_recommendation(rep)
        best_rec = _rep_recommendation(best_rep)
        rank = {"accept": 2, "need_spans": 1, "broken": 0}
        if rank.get(cur_rec, 0) > rank.get(best_rec, 0):
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage
            return
        if rank.get(cur_rec, 0) < rank.get(best_rec, 0):
            return

        cur_cov = _span_coverage(rep)
        best_cov = _span_coverage(best_rep)
        if cur_cov > best_cov + 1e-9:
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage
            return
        if cur_cov < best_cov - 1e-9:
            return

        if score >= threshold and best_score < threshold:
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage
            return

        if score >= best_score:
            best_path, best_score, best_rep, best_stage = seg_path, score, rep, stage

    try:
        # Preload pages once for anti-TOC heuristic + later reconstruction
        pages_for_csp = extract_pages_offset(pdf_path)
        content_start_page = detect_content_start_page(pages_for_csp)

        # 1) deterministic segments.py
        _run([
            sys.executable, str(segmenter_path),
            "--pdf", str(pdf_path),
            "--agenda", str(agenda_path),
            "--out", str(temp_dir),
        ], raise_on_error=True)

        seg_det = temp_dir / "segments.json"
        if not seg_det.exists():
            raise SystemExit("segments.py hat keine segments.json erzeugt.")

        score_det, rep_det = _validate(seg_det, "quality_report_det.json")
        _consider(seg_det, score_det, rep_det, "deterministic")

        det_score = float(score_det)
        det_rec = _rep_recommendation(rep_det)
        det_cov = float(_span_coverage(rep_det))

        if det_rec == "accept" and score_det >= threshold:
            best_path, best_score, best_rep, best_stage = seg_det, score_det, rep_det, "deterministic"
        else:
            # 2) DocAI hints + reconstruct (if enabled and runnable)
            have_dump = _find_docai_dump_near(temp_dir) is not None
            have_key = bool((os.getenv("MISTRAL_API_KEY") or "").strip())

            if args.use_docai and (have_dump or have_key) and (det_rec in ("need_spans", "broken")):
                used_docai = True
                try:
                    hints_path, docai_stdout_tail = run_docai_hints(
                        pdf_path=pdf_path,
                        agenda_path=agenda_path,
                        out_dir=temp_dir,
                        timeout_s=int(args.docai_timeout_s),
                        content_start_page=int(content_start_page),
                        force_full_pdf=bool(args.docai_force_full_pdf),
                    )

                    hints_doc = _read_json(hints_path)
                    recon_doc = reconstruct_spans_from_hints(
                        hints_doc,
                        pdf_path,
                        content_start_page=int(content_start_page),
                    )
                    recon_path = temp_dir / "docai_reconstructed_segments.json"
                    _write_json(recon_path, recon_doc)

                    score_docai_v, rep_docai = _validate(recon_path, "quality_report_docai_reconstructed.json")
                    _consider(recon_path, score_docai_v, rep_docai, "docai_reconstructed")

                    docai_score = float(score_docai_v)
                    docai_rec = _rep_recommendation(rep_docai)
                    docai_cov = float(_span_coverage(rep_docai))
                except SystemExit as e:
                    # DocAI failure is non-fatal: keep deterministic result.
                    docai_stdout_tail = _short_tail(str(e), 4000)
                except Exception as e:
                    docai_stdout_tail = _short_tail(repr(e), 4000)

        # FINAL: write best result
        if best_path is None:
            best_path = seg_det
            best_score, best_rep, best_stage = score_det, rep_det, "deterministic"

        best_doc = _read_json(best_path)

        meta = best_doc.get("meta")
        if not isinstance(meta, dict):
            meta = {}
            best_doc["meta"] = meta

        meta["pipeline_score"] = float(best_score)
        meta["pipeline_threshold"] = float(threshold)
        meta["pipeline_content_start_page"] = int(content_start_page)

        rec_best = _rep_recommendation(best_rep)
        pipeline_ok = (rec_best == "accept") and (best_score >= threshold) and (not _rep_is_broken(best_rep))
        meta["pipeline_ok"] = bool(pipeline_ok)
        meta["pipeline_stage"] = best_stage
        meta["pipeline_recommendation"] = rec_best
        meta["pipeline_used_docai"] = bool(used_docai)

        counts = best_rep.get("counts") or {}
        meta["pipeline_tops_total_meta"] = int(counts.get("tops_total_meta") or 0)
        meta["pipeline_tops_with_span"] = int(counts.get("tops_with_span") or 0)
        span_cov_final = round(_span_coverage(best_rep), 6)
        meta["pipeline_span_coverage"] = span_cov_final

        # Fill missing text_full deterministically where spans exist
        best_doc = enrich_text_full_in_segments(best_doc, pdf_path)

        final_path = package_dir / "segments.json"
        _atomic_write_json(final_path, best_doc)

        print(json.dumps({
            "ok": True,
            "package_dir": str(package_dir),
            "final": str(final_path),
            "threshold": float(threshold),
            "score": float(best_score),
            "stage": best_stage,
            "pipeline_ok": bool(pipeline_ok),
            "recommendation": rec_best,
            "span_coverage": span_cov_final,
            "used_docai": bool(used_docai),
            "content_start_page": int(content_start_page),
            "report": best_rep,
        }, ensure_ascii=False))

    except SystemExit as e:
        error = "system_exit"
        error_stage = best_stage or "unknown"
        error_detail = _short_tail(str(e), 6000)

        print(json.dumps({
            "ok": False,
            "package_dir": str(package_dir),
            "pdf": str(pdf_path),
            "agenda": str(agenda_path),
            "error": error,
            "error_detail": error_detail,
        }, ensure_ascii=False))
        raise

    except Exception as e:
        error = "exception"
        error_stage = best_stage or "unknown"
        error_detail = _short_tail(repr(e), 6000)

        print(json.dumps({
            "ok": False,
            "package_dir": str(package_dir),
            "pdf": str(pdf_path),
            "agenda": str(agenda_path),
            "error": error,
            "error_detail": error_detail,
        }, ensure_ascii=False))
        raise

    finally:
        try:
            counts_final = (best_rep.get("counts") or {}) if isinstance(best_rep, dict) else {}
            row = {
                "ts_utc": _utc_now_iso(),
                "package_dir": str(package_dir),
                "pdf": str(pdf_path),
                "agenda": str(agenda_path),
                "stage_final": best_stage,
                "used_docai": bool(used_docai),
                "pipeline_ok": bool(pipeline_ok),
                "threshold": float(threshold),
                "score_final": float(best_score) if best_score is not None else "",
                "recommendation_final": rec_best,
                "span_coverage_final": span_cov_final,
                "tops_total_meta": int(counts_final.get("tops_total_meta") or 0),
                "tops_with_span": int(counts_final.get("tops_with_span") or 0),
                "det_score": det_score if det_score is not None else "",
                "det_rec": det_rec,
                "det_span_cov": det_cov if det_cov is not None else "",
                "docai_score": docai_score if docai_score is not None else "",
                "docai_rec": docai_rec,
                "docai_span_cov": docai_cov if docai_cov is not None else "",
                "error": error,
                "error_stage": error_stage,
                "error_detail": error_detail,
                "segments_final": str(final_path) if final_path else "",
            }

            jsonl_obj = dict(row)
            jsonl_obj["docai_stdout_tail"] = docai_stdout_tail
            jsonl_obj["report_counts"] = (best_rep.get("counts") if isinstance(best_rep, dict) else None)
            jsonl_obj["report_recommendation"] = (best_rep.get("recommendation") if isinstance(best_rep, dict) else None)
            jsonl_obj["report_score"] = (best_rep.get("score") if isinstance(best_rep, dict) else None)

            _emit_log(row)
            if log_jsonl_path:
                _append_jsonl(log_jsonl_path, jsonl_obj)
        except Exception:
            pass

        if not args.keep_temp:
            _safe_rmtree(temp_dir)


if __name__ == "__main__":
    main()