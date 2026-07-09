#segments_seg_layout.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List, Tuple

import fitz  # PyMuPDF
from rapidfuzz import fuzz

from segments_utils import normalize_text_offset
from segments_seg_types import LayoutLine, RuleAnchor, PageLayoutIndex


def is_bold_fontname(fontname: str) -> bool:
    fn = (fontname or "").lower()
    return any(x in fn for x in ["bold", "black", "heavy", "semibold", "demi"])


def build_page_layout_index(page: fitz.Page) -> PageLayoutIndex:
    d = page.get_text("dict")
    sizes: List[float] = []
    lines_out: List[LayoutLine] = []

    def safe_float(x: Any) -> float:
        try:
            return float(x)
        except Exception:
            return 0.0

    blocks = d.get("blocks") or []
    for b in blocks:
        if b.get("type") != 0:
            continue
        for ln in (b.get("lines") or []):
            span_texts: List[str] = []
            total_chars = 0
            bold_chars = 0
            max_size = 0.0

            for sp in (ln.get("spans") or []):
                txt = sp.get("text") or ""
                if not txt:
                    continue
                span_texts.append(txt)
                n = len(txt)
                total_chars += n

                fsz = safe_float(sp.get("size"))
                if fsz > 0:
                    sizes.append(fsz)
                    if fsz > max_size:
                        max_size = fsz

                font = sp.get("font") or ""
                if is_bold_fontname(font):
                    bold_chars += n

            line_text = normalize_text_offset(" ".join(span_texts))
            if not line_text:
                continue

            bold_ratio = (bold_chars / total_chars) if total_chars > 0 else 0.0
            lines_out.append(LayoutLine(text=line_text, bold_ratio=bold_ratio, max_size=max_size))

    median = 0.0
    if sizes:
        s2 = sorted(sizes)
        mid = len(s2) // 2
        median = float(s2[mid]) if (len(s2) % 2 == 1) else float((s2[mid - 1] + s2[mid]) / 2.0)

    return PageLayoutIndex(lines=lines_out, median_size=median)


def heading_bonus_for_title(layout_index: PageLayoutIndex, title: str) -> Tuple[float, Dict[str, Any]]:
    title = (title or "").strip()
    if not title or not layout_index.lines:
        return 0.0, {"layout_match": 0.0}

    best = None
    best_score = -1.0
    t_low = title.lower()

    for ln in layout_index.lines:
        s = float(fuzz.partial_ratio(t_low, ln.text.lower()))
        if s > best_score:
            best_score = s
            best = ln

    if best is None:
        return 0.0, {"layout_match": best_score}

    bonus = 0.0
    if best_score >= 88.0:
        if best.bold_ratio >= 0.50:
            bonus += 8.0
        elif best.bold_ratio >= 0.30:
            bonus += 4.0

        med = layout_index.median_size if layout_index.median_size > 0 else 0.0
        if med > 0 and best.max_size >= med * 1.15:
            bonus += 4.0
        elif med > 0 and best.max_size >= med * 1.08:
            bonus += 2.0

    return bonus, {
        "layout_match": best_score,
        "bold_ratio": best.bold_ratio,
        "max_size": best.max_size,
        "median_size": layout_index.median_size,
    }


def _is_horizontal_rule_line(
    x0: float,
    y0: float,
    x1: float,
    y1: float,
    page_width: float,
    y_tol: float = 3.0,
    min_frac: float = 0.70,
) -> bool:
    if abs(y0 - y1) > y_tol:
        return False
    if abs(x1 - x0) < page_width * min_frac:
        return False
    return True


def _extract_header_above_y(page: fitz.Page, y: float, band: float = 60.0) -> str:
    """
    Sammelt ALLE Text-Spans aus dem Band [y-band, y] und gibt sie als
    zusammengesetzten String zurück — sortiert nach Position (oben→unten, links→rechts).

    FIX gegenüber alter Implementierung:
    - Alt: nur den EINEN nächsten Block zurückgeben (missed TOP-Keys am Blockende)
    - Neu: alle Spans im Band (wie detect_heading_rules.py), dadurch steht der
      nächste TOP-Key am Ende des heading_text und wird von _header_mentions_top_key
      zuverlässig gefunden.
    """
    y0_band = max(0.0, y - band)
    y1_band = y

    d = page.get_text("dict")
    picked: List[Tuple[float, float, str]] = []  # (y0, x0, text)

    for b in (d.get("blocks") or []):
        if b.get("type") != 0:
            continue
        for ln in (b.get("lines") or []):
            for sp in (ln.get("spans") or []):
                txt = (sp.get("text") or "").strip()
                if not txt:
                    continue
                bbox = sp.get("bbox")
                if not bbox or len(bbox) < 4:
                    continue
                sp_y0, sp_y1 = float(bbox[1]), float(bbox[3])
                sp_x0 = float(bbox[0])
                # Span muss vollständig im Band liegen (y1 <= y_line)
                if sp_y1 > y + 2.0:
                    continue
                if sp_y0 < y0_band:
                    continue
                picked.append((sp_y0, sp_x0, txt))

    if not picked:
        return ""

    picked.sort(key=lambda t: (t[0], t[1]))
    parts = [t[2] for t in picked]
    return normalize_text_offset(" ".join(parts))


def extract_pages_layout_rules(
    pdf_path: Path,
    rule_y_tol: float = 3.0,
    rule_min_frac: float = 0.70,
    rule_header_band: float = 60.0,   # FIX: 120 -> 60 pt, konsistent mit detect_heading_rules.py
    rule_max_per_page: int = 0,  # 0 = keep all
) -> Tuple[List[str], List[PageLayoutIndex], List[List[RuleAnchor]]]:
    """
    Single fitz-open: extracts
      - pages_fit (normalized)
      - layout indices
      - rule anchors per page
    """
    doc = fitz.open(str(pdf_path))

    pages: List[str] = []
    layout: List[PageLayoutIndex] = []
    rules_out: List[List[RuleAnchor]] = []

    try:
        for p in range(doc.page_count):
            page = doc.load_page(p)

            text = page.get_text("text") or ""
            pages.append(normalize_text_offset(text))

            layout.append(build_page_layout_index(page))

            W = float(page.rect.width)
            rules: List[RuleAnchor] = []

            for dr in page.get_drawings() or []:
                for it in (dr.get("items", []) or []):
                    if not it:
                        continue
                    op = it[0]

                    if op == "l" and len(it) >= 3:
                        p1 = it[1]
                        p2 = it[2]
                        x0, y0 = float(p1.x), float(p1.y)
                        x1, y1 = float(p2.x), float(p2.y)
                        if _is_horizontal_rule_line(x0, y0, x1, y1, W, y_tol=rule_y_tol, min_frac=rule_min_frac):
                            y = (y0 + y1) / 2.0
                            hdr = _extract_header_above_y(page, y, band=rule_header_band)
                            rules.append(RuleAnchor(page=p, y=y, x0=min(x0, x1), x1=max(x0, x1), header_text=hdr))

                    elif op == "re" and len(it) >= 2:
                        r = it[1]  # fitz.Rect
                        x0, y0, x1, y1 = float(r.x0), float(r.y0), float(r.x1), float(r.y1)
                        h = abs(y1 - y0)
                        w = abs(x1 - x0)
                        if h <= (rule_y_tol * 2.0) and w >= W * rule_min_frac:
                            y = (y0 + y1) / 2.0
                            hdr = _extract_header_above_y(page, y, band=rule_header_band)
                            rules.append(RuleAnchor(page=p, y=y, x0=min(x0, x1), x1=max(x0, x1), header_text=hdr))

            rules.sort(key=lambda r: r.y)
            if rule_max_per_page and len(rules) > rule_max_per_page:
                rules = rules[:rule_max_per_page]

            rules_out.append(rules)

    finally:
        doc.close()

    return pages, layout, rules_out
