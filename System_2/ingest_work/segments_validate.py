# segments_validate.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
segments_validate.py — Validate segments.json for RAG suitability (agenda-driven minutes)

Shared OFFSET SPACE:
- Uses segments_utils.extract_pages_offset + collect_text_for_span
- Therefore spans created/reconstructed in the pipeline are validated/extracted
  in the same OFFSET SPACE.

Output:
- quality_report.json with score + issues + diagnostics:
  span_coverage, text_coverage, recommendation ("accept" | "need_spans" | "broken")
"""

import argparse
import json
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from rapidfuzz import fuzz
from segments_utils import normalize_text_match

from segments_utils import (
    extract_pages_offset,
    collect_text_for_span,
    top_key_regex_line_start,  # SINGLE SOURCE OF TRUTH
)

WS_ONLY_RE = re.compile(r"^\s*$", re.M | re.S)
_PAGE_MARKER_RE = re.compile(r"(?mi)^\s*-\s*\d+\s*-\s*$")


def _span_pos(page: int, char: int) -> Tuple[int, int]:
    return (int(page), int(char))


@dataclass
class Issue:
    type: str
    severity: str  # "severe"|"warn"|"info"
    top_key: Optional[str]
    details: str
    span: Optional[Dict[str, Any]] = None


def has_full_span(seg: Dict[str, Any]) -> bool:
    return (
        isinstance(seg.get("start_page"), int)
        and isinstance(seg.get("start_char"), int)
        and isinstance(seg.get("end_page"), int)
        and isinstance(seg.get("end_char"), int)
    )


def clamp_span(seg: Dict[str, Any], pages: List[str]) -> Tuple[int, int, int, int]:
    pages_total = len(pages)
    sp = int(seg["start_page"])
    sc = int(seg["start_char"])
    ep = int(seg["end_page"])
    ec = int(seg["end_char"])

    sp = max(1, min(sp, pages_total))
    ep = max(1, min(ep, pages_total))
    if ep < sp:
        ep = sp

    sc = max(0, min(sc, len(pages[sp - 1])))
    ec = max(0, min(ec, len(pages[ep - 1])))
    if ep == sp and ec < sc:
        ec = sc
    return sp, sc, ep, ec


def gap_is_only_whitespace_or_markers(
    pages: List[str],
    a_end: Dict[str, Any],
    b_start: Dict[str, Any],
) -> bool:
    ap = int(a_end["end_page"])
    ac = int(a_end["end_char"])
    bp = int(b_start["start_page"])
    bc = int(b_start["start_char"])

    if ap < 1 or bp < 1 or ap > len(pages) or bp > len(pages):
        return False
    if ap > bp:
        return False

    def _ok_text(t: str) -> bool:
        if not t:
            return True
        if WS_ONLY_RE.match(t):
            return True
        lines = [ln for ln in (t or "").splitlines() if ln.strip()]
        if lines and all(_PAGE_MARKER_RE.match(ln) for ln in lines):
            return True
        return False

    if ap == bp:
        page_txt = pages[ap - 1]
        ac2 = max(0, min(ac, len(page_txt)))
        bc2 = max(0, min(bc, len(page_txt)))
        if bc2 < ac2:
            bc2 = ac2
        gap = page_txt[ac2:bc2]
        return _ok_text(gap)

    tail_txt = pages[ap - 1]
    head_txt = pages[bp - 1]
    ac2 = max(0, min(ac, len(tail_txt)))
    bc2 = max(0, min(bc, len(head_txt)))

    if not _ok_text(tail_txt[ac2:]):
        return False
    if not _ok_text(head_txt[:bc2]):
        return False

    for p1 in range(ap + 1, bp):
        if p1 < 1 or p1 > len(pages):
            continue
        if not _ok_text(pages[p1 - 1]):
            return False

    return True

def _looks_like_real_top_heading(
    text: str,
    m_start: int,
    *,
    nxt_key: str,
    nxt_title: str,
) -> bool:
    """
    Decide whether a match for next TOP key inside current TOP is likely a real TOP heading
    (vs. a numbered sub-item like '1.' or '2.').
    """

    # Work on a small window around the match
    ctx = text[max(0, m_start - 80): min(len(text), m_start + 500)]
    ctx_n = normalize_text_match(ctx).lower()

    # Strong signal: typical protocol heading markers right after key
    if re.search(r"(?i)\bberichterstatter\b|\bbericht\s+erstattet\b", ctx):
        return True

    # Title signal: next TOP title words appear near the match (cheap gate)
    title_n = normalize_text_match(nxt_title or "").lower()
    if title_n:
        # fuzzy against context
        score = float(fuzz.partial_ratio(title_n, ctx_n))
        if score >= 72.0:
            return True

        # also allow if >=2 "long" title words appear
        words = [w for w in re.split(r"\s+", title_n) if len(w) >= 6]
        hit = sum(1 for w in words[:6] if w and w in ctx_n)
        if hit >= 2:
            return True

    # Very short line heuristics: true headings often sit on their own line with text after
    # but numbered subitems are also short; so we do NOT accept on that alone.
    return False

def top_key_heading_regex(top_key: str) -> re.Pattern:
    """
    Findet TOP-Überschriften (Zeilenanfang) und blockt Datums-/Subkey-Fälle:
      - blockt "26.01.2012" (Datum), weil nach '.' direkt eine Ziffer kommt
      - blockt "22.1" usw. (Sub-TOP ohne Leerzeichen)
      - erlaubt "26. Sitzung..." bzw. "26. <Text>" (Punkt + Space)
    """
    k = (top_key or "").strip()
    if not k:
        return re.compile(r"$^")

    # optionaler Seitenkopf wie "- 16 - 22."
    prefix = r"(?:[-–—]\s*\d+\s*[-–—]\s*)?"

    if re.fullmatch(r"\d+", k):
        esc = re.escape(k)
        # wichtig: (?!\.\d) blockt Datum "26.01" und "22.1"
        return re.compile(rf"(?mi)(^|\n)\s*{prefix}{esc}\s*(?!\.\d)\s*[)\.:]\s+")
    else:
        esc = re.escape(k).replace(r"\.", r"\s*\.\s*")
        return re.compile(rf"(?mi)(^|\n)\s*{prefix}{esc}\s*(?:[)\.:]|\s)\s+")

def validate(
    pdf_path: Path,
    segments_path: Path,
    out_path: Path,
    min_top_chars: int = 20,
    marker_near_start_chars: int = 600,
    leakage_early_window: int = 1400,
    threshold: float = 90.0,
    patch_segments: bool = False,
) -> Dict[str, Any]:
    raw = json.loads(segments_path.read_text(encoding="utf-8"))
    meta = raw.get("meta") or {}
    segs = raw.get("segments") or []

    pages = extract_pages_offset(pdf_path)
    pages_total = len(pages)

    if pages_total <= 0:
        out = {
            "pdf": str(pdf_path),
            "segments": str(segments_path),
            "meta": meta,
            "score": 0.0,
            "threshold": float(threshold),
            "ok": False,
            "recommendation": "broken",
            "diagnostics": {"span_coverage": 0.0, "text_coverage": 0.0},
            "counts": {
                "issues_total": 1,
                "issues_severe": 1,
                "tops_total": 0,
                "tops_with_span": 0,
                "tops_total_meta": int(meta.get("tops_total") or 0),
                "tops_missing_meta": None,
            },
            "issues": [{
                "type": "NO_PAGES",
                "severity": "severe",
                "top_key": None,
                "details": "PDF text extraction returned no pages.",
                "span": None,
            }],
        }
        out_path.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")
        return out

    content_start_page = int(meta.get("content_start_page") or 1)
    content_start_char = int(meta.get("content_start_char") or 0)
    content_end_page = int(meta.get("content_end_page") or pages_total)

    content_start_page = max(1, min(content_start_page, pages_total))
    content_end_page = max(1, min(content_end_page, pages_total))
    if content_end_page < content_start_page:
        content_end_page = content_start_page

    content_end_char = meta.get("content_end_char")
    if isinstance(content_end_char, int) and content_end_char >= 0:
        content_end_char = min(content_end_char, len(pages[content_end_page - 1]))
    else:
        content_end_char = len(pages[content_end_page - 1])

    segs_list: List[Dict[str, Any]] = [s for s in segs if isinstance(s, dict)]
    tops: List[Dict[str, Any]] = [s for s in segs_list if (s.get("type") == "top")]
    vorworts = [s for s in segs_list if s.get("type") == "vorwort"]
    nachworts = [s for s in segs_list if s.get("type") == "nachwort"]
    anhaenge = [s for s in segs_list if s.get("type") == "anhang"]

    tops_ok = [t for t in tops if has_full_span(t)]
    tops_ok.sort(key=lambda t: (int(t["start_page"]), int(t["start_char"])))

    issues: List[Issue] = []
    score = 100.0

    tops_total_meta = int(meta.get("tops_total") or 0)
    tops_expected = tops_total_meta if tops_total_meta > 0 else len(tops)

    span_coverage = (len(tops_ok) / float(tops_expected)) if tops_expected > 0 else 0.0

    tops_with_text = sum(1 for t in tops if isinstance(t.get("text_full"), str) and t.get("text_full", "").strip())
    text_coverage = (tops_with_text / float(len(tops))) if len(tops) > 0 else 0.0

    tops_missing_meta = max(0, tops_total_meta - len(tops_ok)) if tops_total_meta > 0 else None

    if tops_total_meta > 0:
        if len(tops_ok) == 0:
            issues.append(Issue(
                "NO_TOPS_WITH_SPAN", "warn", None,
                f"No TOP segments with full span found, but meta expects {tops_total_meta} TOPs."
            ))
            score -= 35.0
        elif tops_missing_meta and tops_missing_meta > 0:
            frac_ok = len(tops_ok) / float(tops_total_meta)
            issues.append(Issue(
                "TOPS_MISSING_SPAN", "warn", None,
                f"{tops_missing_meta} of {tops_total_meta} TOPs are missing start/end spans (only {len(tops_ok)} have spans).",
                span={
                    "tops_total_meta": tops_total_meta,
                    "tops_with_span": len(tops_ok),
                    "tops_missing_meta": tops_missing_meta,
                    "fraction_with_span": round(frac_ok, 3),
                }
            ))
            score -= 15.0
            score -= max(0.0, (0.90 - frac_ok) * 60.0)

    if len(tops_ok) == 0 and text_coverage < 0.2 and len(tops) > 0:
        issues.append(Issue(
            "NO_USABLE_TOP_CONTENT", "severe", None,
            "No TOP spans and most TOPs have empty text_full; output is not useful for RAG.",
            span={"text_coverage": round(text_coverage, 3)}
        ))
        score = min(score, 5.0)

    patched = False
    if patch_segments:
        for s in segs_list:
            if has_full_span(s) and not (isinstance(s.get("text_full"), str) and s["text_full"].strip()):
                sp, sc, ep, ec = clamp_span(s, pages)
                s["text_full"] = collect_text_for_span(pages, sp, sc, ep, ec)
                patched = True
        if patched:
            raw["segments"] = segs_list
            segments_path.write_text(json.dumps(raw, ensure_ascii=False, indent=2), encoding="utf-8")

    prev_end: Optional[Dict[str, int]] = None
    for i, cur in enumerate(tops_ok):
        sp, sc, ep, ec = clamp_span(cur, pages)

        if sp < content_start_page or (sp == content_start_page and sc < content_start_char):
            issues.append(Issue(
                "OUT_OF_BOUNDS_START", "warn", cur.get("top_key"),
                "TOP starts before content_start.",
                span={"start_page": sp, "start_char": sc, "content_start_page": content_start_page, "content_start_char": content_start_char}
            ))
            score -= 2.0

        if ep > content_end_page or (ep == content_end_page and ec > content_end_char):
            issues.append(Issue(
                "OUT_OF_BOUNDS_END", "warn", cur.get("top_key"),
                "TOP ends after content_end.",
                span={"end_page": ep, "end_char": ec, "content_end_page": content_end_page, "content_end_char": content_end_char}
            ))
            score -= 2.0

        if prev_end is not None:
            pp = int(prev_end["end_page"])
            pc = int(prev_end["end_char"])

            if (sp < pp) or (sp == pp and sc < pc):
                issues.append(Issue(
                    "OVERLAP_BETWEEN_TOPS", "severe", cur.get("top_key"),
                    f"TOP overlaps previous end (prev_end={pp}:{pc}, cur_start={sp}:{sc}).",
                    span={"prev_end_page": pp, "prev_end_char": pc, "cur_start_page": sp, "cur_start_char": sc}
                ))
                score -= 12.0
            elif (sp > pp) or (sp == pp and sc > pc):
                if not gap_is_only_whitespace_or_markers(pages, {"end_page": pp, "end_char": pc}, {"start_page": sp, "start_char": sc}):
                    issues.append(Issue(
                        "GAP_BETWEEN_TOPS", "warn", cur.get("top_key"),
                        f"Non-whitespace gap between previous end and current start (prev_end={pp}:{pc}, cur_start={sp}:{sc}).",
                        span={"prev_end_page": pp, "prev_end_char": pc, "cur_start_page": sp, "cur_start_char": sc}
                    ))
                    score -= 4.0

        text = cur.get("text_full")
        if not (isinstance(text, str) and text.strip()):
            text = collect_text_for_span(pages, sp, sc, ep, ec)

        if len(text) < int(min_top_chars):
            issues.append(Issue(
                "TOP_TOO_SHORT", "info", cur.get("top_key"),
                f"TOP text is short ({len(text)} chars < {min_top_chars}).",
                span={"start_page": sp, "start_char": sc, "end_page": ep, "end_char": ec, "chars": len(text)}
            ))

        key = (cur.get("top_key") or "").strip()
        if key:
            pat = top_key_regex_line_start(key)
            head = text[:max(int(marker_near_start_chars), 200)]
            if not pat.search(head):
                issues.append(Issue(
                    "MARKER_NOT_NEAR_START", "warn", cur.get("top_key"),
                    f"TOP key '{key}' not found near start (first {len(head)} chars).",
                ))
                score -= 3.0

        if i + 1 < len(tops_ok):
            nxt_key = (tops_ok[i + 1].get("top_key") or "").strip()
            if nxt_key:
                nxt_pat = top_key_heading_regex(nxt_key)
                early = text[:int(leakage_early_window)]

                m = nxt_pat.search(early)
                if m:
                    nxt_title = (tops_ok[i + 1].get("title") or "").strip()
                    if _looks_like_real_top_heading(early, m.start(), nxt_key=nxt_key, nxt_title=nxt_title):
                        issues.append(Issue(
                            "LEAK_NEXT_TOP_EARLY", "severe", cur.get("top_key"),
                            f"Next TOP key '{nxt_key}' appears early inside current TOP (first {len(early)} chars).",
                        ))
                        score -= 10.0
                    else:
                        # downgrade: informational only
                        issues.append(Issue(
                            "LEAK_NEXT_TOP_EARLY_SUPPRESSED", "info", cur.get("top_key"),
                            f"Next key '{nxt_key}' matched early, but looks like a sub-item (suppressed).",
                        ))

        prev_end = {"end_page": ep, "end_char": ec}

    if tops_ok:
        last = tops_ok[-1]
        lp = int(last["end_page"])
        lc = int(last["end_char"])
        lp = max(1, min(lp, pages_total))
        lc = max(0, min(lc, len(pages[lp - 1])))

        if _span_pos(content_end_page, content_end_char) > _span_pos(lp, lc):
            gap_txt = collect_text_for_span(pages, lp, lc, content_end_page, content_end_char)
            if gap_txt and not WS_ONLY_RE.match(gap_txt):
                issues.append(Issue(
                    "TRAILING_GAP_AFTER_LAST_TOP", "warn", last.get("top_key"),
                    "Non-whitespace trailing content after last TOP within content space (consider nachwort span).",
                    span={"last_end_page": lp, "last_end_char": lc, "content_end_page": content_end_page, "content_end_char": content_end_char, "gap_chars": len(gap_txt)}
                ))
                score -= 6.0

    def _first_spanned(seglist: List[Dict[str, Any]]) -> Optional[Tuple[int, int, int, int]]:
        cands = [clamp_span(s, pages) for s in seglist if has_full_span(s)]
        if not cands:
            return None
        cands.sort(key=lambda x: (x[0], x[1]))
        return cands[0]

    def _last_spanned(seglist: List[Dict[str, Any]]) -> Optional[Tuple[int, int, int, int]]:
        cands = [clamp_span(s, pages) for s in seglist if has_full_span(s)]
        if not cands:
            return None
        cands.sort(key=lambda x: (x[2], x[3]))
        return cands[-1]

    top1 = tops_ok[0] if tops_ok else None
    toplast = tops_ok[-1] if tops_ok else None

    if top1:
        t1s = clamp_span(top1, pages)
        v_last = _last_spanned(vorworts)
        if v_last and _span_pos(v_last[2], v_last[3]) > _span_pos(t1s[0], t1s[1]):
            issues.append(Issue(
                "VORWORT_OVERLAPS_TOP1", "severe", None,
                "Vorwort ends after TOP1 starts.",
                span={"vorwort_end": f"{v_last[2]}:{v_last[3]}", "top1_start": f"{t1s[0]}:{t1s[1]}"}
            ))
            score -= 12.0

    if toplast:
        tls = clamp_span(toplast, pages)
        n_first = _first_spanned(nachworts)
        if n_first and _span_pos(n_first[0], n_first[1]) < _span_pos(tls[2], tls[3]):
            issues.append(Issue(
                "NACHWORT_OVERLAPS_LAST_TOP", "severe", None,
                "Nachwort starts before last TOP ends.",
                span={"nachwort_start": f"{n_first[0]}:{n_first[1]}", "last_top_end": f"{tls[2]}:{tls[3]}"}
            ))
            score -= 12.0

    spanned = [s for s in segs_list if has_full_span(s)]
    spanned.sort(key=lambda s: (int(s["start_page"]), int(s["start_char"])))
    prev = None
    prev_seg_type: Optional[str] = None
    for s in spanned:
        sp, sc, ep, ec = clamp_span(s, pages)
        if prev is not None:
            _, _, pe, pec = prev
            if _span_pos(sp, sc) < _span_pos(pe, pec):
                issues.append(Issue(
                    "OVERLAP_BETWEEN_SEGMENTS", "severe",
                    s.get("top_key") if s.get("type") == "top" else None,
                    f"Segment overlap detected (prev_end={pe}:{pec}, cur_start={sp}:{sc}).",
                    span={"prev_type": prev_seg_type or "unknown", "cur_type": s.get("type"), "prev_end": f"{pe}:{pec}", "cur_start": f"{sp}:{sc}"}
                ))
                score -= 12.0
        prev = (sp, sc, ep, ec)
        prev_seg_type = s.get("type")

    severe = [x for x in issues if x.severity == "severe"]

    if severe:
        recommendation = "broken"
    else:
        if tops_total_meta > 0 and len(tops_ok) == tops_total_meta and score >= float(threshold):
            recommendation = "accept"
        else:
            recommendation = "accept" if (span_coverage >= 0.85 and score >= float(threshold)) else "need_spans"

    score = max(0.0, min(100.0, score))
    ok = (len(severe) == 0 and score >= float(threshold))

    out = {
        "pdf": str(pdf_path),
        "segments": str(segments_path),
        "meta": meta,
        "score": round(score, 2),
        "threshold": float(threshold),
        "ok": ok,
        "recommendation": recommendation,
        "diagnostics": {
            "span_coverage": round(span_coverage, 3),
            "text_coverage": round(text_coverage, 3),
            "patched_text_full": bool(patched),
            "content_space": {
                "content_start": f"{content_start_page}:{content_start_char}",
                "content_end": f"{content_end_page}:{content_end_char}",
            },
            "segments_count": {
                "tops": len(tops),
                "vorwort": len(vorworts),
                "nachwort": len(nachworts),
                "anhang": len(anhaenge),
            },
        },
        "counts": {
            "issues_total": len(issues),
            "issues_severe": len(severe),
            "tops_total": len(tops),
            "tops_with_span": len(tops_ok),
            "tops_total_meta": tops_total_meta,
            "tops_missing_meta": tops_missing_meta,
        },
        "issues": [x.__dict__ for x in issues],
    }

    out_path.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--segments", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--min_top_chars", type=int, default=20)
    ap.add_argument("--marker_near_start_chars", type=int, default=600)
    ap.add_argument("--leakage_early_window", type=int, default=1400)
    ap.add_argument("--threshold", type=float, default=90.0)
    ap.add_argument(
        "--patch_segments",
        action="store_true",
        help="Fill missing/empty text_full deterministically from PDF, but only where spans exist.",
    )
    args = ap.parse_args()

    validate(
        pdf_path=Path(args.pdf),
        segments_path=Path(args.segments),
        out_path=Path(args.out),
        min_top_chars=args.min_top_chars,
        marker_near_start_chars=args.marker_near_start_chars,
        leakage_early_window=args.leakage_early_window,
        threshold=args.threshold,
        patch_segments=bool(args.patch_segments),
    )


if __name__ == "__main__":
    main()