#segments.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
segments.py (deterministic, agenda-driven)
Orchestrierung + Segment-Assembly (Rest ausgelagert in segments_seg_*).
"""

import argparse
import json
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from pypdf import PdfReader

from segments_utils import normalize_text_offset, collect_text_for_span

from segments_seg_types import Candidate, PageLayoutIndex
from segments_seg_layout import extract_pages_layout_rules
from segments_seg_content import (
    load_agenda,
    detect_content_start_by_flowtext,
    detect_appendix_start_page,
    find_inline_appendix_offset,
    find_nachwort_marker_offset,
    absolute_pos_from_span_offset,
)
from segments_seg_match import build_candidates_for_top, dp_choose_best_path


# -------------------------
# local helpers
# -------------------------

def next_found_index(found_starts: List[Optional[Tuple[int, int]]], i: int) -> Optional[int]:
    for j in range(i + 1, len(found_starts)):
        if found_starts[j] is not None:
            return j
    return None


def extract_pages_pypdf(pdf_path: Path) -> List[str]:
    reader = PdfReader(str(pdf_path))
    pages: List[str] = []
    for p in reader.pages:
        try:
            t = p.extract_text() or ""
        except Exception:
            t = ""
        pages.append(normalize_text_offset(t))
    return pages


def merge_pages(primary: List[str], secondary: List[str]) -> List[str]:
    n = max(len(primary), len(secondary))
    out: List[str] = []
    for i in range(n):
        a = primary[i] if i < len(primary) else ""
        b = secondary[i] if i < len(secondary) else ""
        out.append(a if a else b)
    return out


def count_found(path: List[Optional[Candidate]]) -> int:
    return sum(1 for c in path if c is not None)


# -------------------------
# main
# -------------------------

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--agenda", required=True)
    ap.add_argument("--out", required=True)

    ap.add_argument("--appendix_start_page", type=int, default=0)    # 1-based override
    ap.add_argument("--min_marker_fraction", type=float, default=0.45)
    ap.add_argument("--no_text_full", action="store_true", help="Disable text_full extraction (default: enabled)")
    ap.add_argument("--debug_rules", action="store_true", help="Print detected rule anchors (separator lines)")

    args = ap.parse_args()

    pdf_path = Path(args.pdf)
    agenda_path = Path(args.agenda)
    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    emit_text_full = (not args.no_text_full)

    tops = load_agenda(agenda_path)
    if not tops:
        raise SystemExit("Agenda ist leer oder nicht erkannt.")

    # ONE fitz open: pages_fit + layout + rules
    pages_fit, layout, rule_anchors = extract_pages_layout_rules(pdf_path)

    # fallback extraction
    pages_pdf = extract_pages_pypdf(pdf_path)
    pages = merge_pages(pages_fit, pages_pdf)

    if not pages:
        raise SystemExit("Keine Seiten extrahiert.")

    # pad rule_anchors/layout if needed (safety)
    if len(rule_anchors) < len(pages):
        rule_anchors = rule_anchors + [[] for _ in range(len(pages) - len(rule_anchors))]
    if len(layout) < len(pages):
        layout = layout + [PageLayoutIndex([], 0.0) for _ in range(len(pages) - len(layout))]

    if args.debug_rules:
        print("=== RULE DEBUG ===")
        for i, rules in enumerate(rule_anchors):
            if rules:
                print(
                    f"[rules] page={i+1} count={len(rules)} "
                    f"y={[round(r.y,1) for r in rules]} "
                    f"hdr={[r.header_text[:80] for r in rules]}"
                )
        print("=== END RULE DEBUG ===")

    agenda_end, content_start_page, content_start_char = detect_content_start_by_flowtext(pages, debug=args.debug_rules)

    # Für Candidate-Suche erstmal komplettes Dokument zulassen.
    # Appendix wird später (nach chosen/found_starts) im Tail ermittelt und begrenzt dann content_end_*.
    appendix_start: Optional[int] = None
    content_end_page = len(pages) - 1
    content_end_char = len(pages[content_end_page])  # exclusive

    base = Candidate(page=content_start_page, char=max(-1, content_start_char - 1), score=0.0, method="base")

    all_candidates: List[List[Candidate]] = []
    for t in tops:
        all_candidates.append(
            build_candidates_for_top(pages, layout, rule_anchors, t, content_start_page, content_end_page)
        )

    # TOP1 fallback widen search
    if all_candidates and not all_candidates[0]:
        all_candidates[0] = build_candidates_for_top(
            pages, layout, rule_anchors, tops[0], max(0, content_start_page - 2), content_end_page
        )
    if all_candidates and not all_candidates[0]:
        all_candidates[0] = build_candidates_for_top(
            pages, layout, rule_anchors, tops[0], 0, content_end_page
        )

    if args.debug_rules:
        for i, (t, cands) in enumerate(zip(tops, all_candidates)):
            print(f"  TOP {t.key}: {len(cands)} cands, top3={[(c.score, c.page+1, c.method) for c in cands[:3]]}")

    chosen = dp_choose_best_path(all_candidates, base)

    if args.debug_rules:
        print(f"[debug] found0={count_found(chosen)}")
        for i, (t, c) in enumerate(zip(tops, chosen)):
            if c:
                print(f"  TOP {t.key} -> p{c.page+1} [{c.method}]")
            else:
                print(f"  TOP {t.key} -> MISS ({len(all_candidates[i])} cands)")

    found0 = count_found(chosen)
    if found0 < len(tops):
        repair_start = max(0, agenda_end)
        all_candidates_r: List[List[Candidate]] = []
        for i, t in enumerate(tops):
            if i < len(chosen) and chosen[i] is not None:
                all_candidates_r.append(all_candidates[i])
                continue
            c1 = build_candidates_for_top(pages, layout, rule_anchors, t, repair_start, content_end_page)
            if not c1:
                c1 = build_candidates_for_top(pages, layout, rule_anchors, t, 0, content_end_page)
            all_candidates_r.append(c1)

        chosen_r = dp_choose_best_path(all_candidates_r, base)
        if count_found(chosen_r) > found0:
            all_candidates = all_candidates_r
            chosen = chosen_r

    # Precompute found starts for "next found" end boundary (0-based for internal)
    found_starts: List[Optional[Tuple[int, int]]] = []
    for c in chosen:
        if c is None:
            found_starts.append(None)
        else:
            found_starts.append((c.page, max(0, c.char)))

    # -------------------------
    # Appendix-Erkennung NUR im Tail (nach letztem gefundenen TOP)
    # -------------------------
    last_found_i = None
    for ii in reversed(range(len(found_starts))):
        if found_starts[ii] is not None:
            last_found_i = ii
            break

    tail_start0 = max(0, content_start_page + 1)
    if last_found_i is not None and found_starts[last_found_i] is not None:
        lp0, _lc0 = found_starts[last_found_i]
        tail_start0 = max(tail_start0, int(lp0))  # ab Seite des letzten TOPs suchen

    if args.appendix_start_page > 0:
        appendix_start = max(0, min(len(pages) - 1, args.appendix_start_page - 1))
    else:
        appendix_start = detect_appendix_start_page(pages, start_search=tail_start0)

    if appendix_start is None:
        content_end_page = len(pages) - 1
    else:
        content_end_page = max(content_start_page, appendix_start - 1)

    content_end_char = len(pages[content_end_page])  # exclusive

    top_segments: List[Dict[str, Any]] = []
    for i, t in enumerate(tops):
        c = chosen[i] if i < len(chosen) else None
        if c is None:
            top_segments.append({
                "type": "top",
                "top_key": t.key,
                "title": t.title,
                "start_page": None,
                "start_char": None,
                "end_page": None,
                "end_char": None,
                "match_method": "missing",
                "match_score": None,
                "confidence": 0.0,
                "text_full": "",
            })
            continue

        jn = next_found_index(found_starts, i)
        if jn is not None and found_starts[jn] is not None:
            npage0, nchar0 = found_starts[jn]
            ep0, ec0 = int(npage0), int(nchar0)
        else:
            ep0, ec0 = content_end_page, content_end_char

        if (ep0 < c.page) or (ep0 == c.page and ec0 < c.char):
            ep0, ec0 = c.page, max(0, c.char)

        conf = 0.95
        if c.method in ["key_title", "title_heading"] and c.score >= 120:
            conf = 0.99

        rec = {
            "type": "top",
            "top_key": t.key,
            "title": t.title,
            "start_page": c.page + 1,
            "start_char": max(0, c.char),
            "end_page": ep0 + 1,
            "end_char": max(0, ec0),
            "match_method": c.method,
            "match_score": c.score,
            "confidence": conf,
        }
        rec["text_full"] = collect_text_for_span(
            pages, rec["start_page"], rec["start_char"], rec["end_page"], rec["end_char"]
        ) if emit_text_full else ""
        top_segments.append(rec)

    segments: List[Dict[str, Any]] = []

    # Vorwort: from content_start to TOP1 start (only if we have TOP1)
    first_top = next((t for t in top_segments if t.get("start_page") is not None), None)
    if first_top is not None:
        fs = int(first_top["start_page"])
        fc = int(first_top["start_char"])
        vs_page = content_start_page + 1
        vs_char = max(0, content_start_char)
        if vs_page < fs or (vs_page == fs and fc > vs_char):
            vor = {
                "type": "vorwort",
                "title": "Vorwort",
                "start_page": vs_page,
                "start_char": vs_char,
                "end_page": fs,
                "end_char": fc,
                "confidence": 0.98,
                "match_method": "first_flowtext_to_top1",
            }
            vor["text_full"] = collect_text_for_span(
                pages, vor["start_page"], vor["start_char"], vor["end_page"], vor["end_char"]
            ) if emit_text_full else ""
            segments.append(vor)

    # Nachwort/Inline-Anhang detection only if we have a last found TOP
    last_idx = None
    for i in reversed(range(len(top_segments))):
        if top_segments[i].get("start_page") is not None:
            last_idx = i
            break

    nachwort_seg: Optional[Dict[str, Any]] = None
    if last_idx is not None:
        last = top_segments[last_idx]
        sp1 = int(last["start_page"])
        sc = int(last["start_char"])
        ep1 = int(last["end_page"])
        ec = int(last["end_char"])

        last_text = collect_text_for_span(pages, sp1, sc, ep1, ec)

        off_app = find_inline_appendix_offset(last_text, min_fraction=float(args.min_marker_fraction))
        if off_app is not None:
            abs_pos_app = absolute_pos_from_span_offset(pages, sp1, sc, ep1, ec, off_app, join_sep="\n\n")
            if abs_pos_app is not None:
                ap_page1, ap_char = abs_pos_app

                last["end_page"] = ap_page1
                last["end_char"] = ap_char
                last["text_full"] = collect_text_for_span(
                    pages, last["start_page"], last["start_char"], last["end_page"], last["end_char"]
                ) if emit_text_full else ""

                ep1 = int(last["end_page"])
                ec = int(last["end_char"])
                last_text = collect_text_for_span(pages, sp1, sc, ep1, ec)

        off = find_nachwort_marker_offset(last_text, min_fraction=float(args.min_marker_fraction))
        if off is not None:
            abs_pos = absolute_pos_from_span_offset(pages, sp1, sc, ep1, ec, off, join_sep="\n\n")
            if abs_pos is not None:
                marker_page1, marker_char = abs_pos

                last["end_page"] = marker_page1
                last["end_char"] = marker_char
                last["text_full"] = collect_text_for_span(
                    pages, last["start_page"], last["start_char"], last["end_page"], last["end_char"]
                ) if emit_text_full else ""

                nach = {
                    "type": "nachwort",
                    "title": "Nachwort",
                    "start_page": marker_page1,
                    "start_char": marker_char,
                    "end_page": content_end_page + 1,
                    "end_char": content_end_char,
                    "confidence": 0.98,
                    "match_method": "closing_marker_last_top",
                }
                nach["text_full"] = collect_text_for_span(
                    pages, nach["start_page"], nach["start_char"], nach["end_page"], nach["end_char"]
                ) if emit_text_full else ""
                nachwort_seg = nach

    segments.extend(top_segments)

    if nachwort_seg is not None:
        segments.append(nachwort_seg)

    # Appendix segment (page-based)
    if appendix_start is not None:
        anhang_seg = {
            "type": "anhang",
            "title": "Anhang",
            "start_page": appendix_start + 1,
            "start_char": 0,
            "end_page": len(pages),
            "end_char": len(pages[-1]) if pages else 0,
            "confidence": 0.95,
            "match_method": "appendix_heading",
        }
        anhang_seg["text_full"] = collect_text_for_span(
            pages, anhang_seg["start_page"], anhang_seg["start_char"], anhang_seg["end_page"], anhang_seg["end_char"]
        ) if emit_text_full else ""
        segments.append(anhang_seg)

    meta = {
        "pdf": str(pdf_path),
        "pdf_name": pdf_path.name,
        "pages_total": len(pages),
        "agenda_end_page": agenda_end + 1,
        "content_start_page": content_start_page + 1,
        "content_start_char": int(content_start_char),
        "content_end_page": content_end_page + 1,
        "content_end_char": int(content_end_char),
        "appendix_start_page": (appendix_start + 1) if appendix_start is not None else None,
        "tops_total": len(tops),
        "tops_found": sum(1 for t in top_segments if t.get("start_page") is not None),
        "min_marker_fraction": float(args.min_marker_fraction),
        "emit_text_full": bool(emit_text_full),
    }

    out_json = out_dir / "segments.json"
    out_json.write_text(
        json.dumps({"title": "Minutes_Segmented", "meta": meta, "segments": segments}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(json.dumps(
        {"ok": True, "out": str(out_json), "tops_found": meta["tops_found"], "tops_total": meta["tops_total"]},
        ensure_ascii=False
    ))


if __name__ == "__main__":
    main()