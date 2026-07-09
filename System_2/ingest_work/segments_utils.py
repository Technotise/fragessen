# segments_utils.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
segments_utils.py — shared helpers for KommRAG segmentation/validation/pipeline

Purpose
- Provide ONE canonical "offset space" normalization used for:
  - span generation (segments.py)
  - span-based text extraction (pipeline enrichment)
  - span-based validation (segments_validate.py)
  - span reconstruction from DocAI hints (segments_pipeline.py)

Important
- Offsets (start_char/end_char) MUST always refer to the OFFSET space.
- Never use match-space strings to write spans.

Conventions
- pages[] are 0-based in memory
- sp1/ep1 are 1-based pages when stored in JSON
- sc/ec are 0-based char indices; ec is exclusive
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import fitz  # PyMuPDF

try:
    from pypdf import PdfReader  # optional fallback
except Exception:
    PdfReader = None


# ----------------------------
# Normalization (OFFSET SPACE)
# ----------------------------

_RE_HYPHEN_BREAK = re.compile(r"(\w)-\n(\w)")
_RE_SPACES = re.compile(r"[ \t]+")
_RE_MANY_NL = re.compile(r"\n{3,}")


def normalize_text_offset(s: str) -> str:
    """
    Canonical normalization for OFFSET SPACE.
    This MUST be used anywhere start_char/end_char are computed or consumed.

    - remove soft hyphen (U+00AD)
    - normalize CR/FF to LF
    - join hyphenation across line breaks: 'Wort-\\ntrennung' -> 'Worttrennung'
    - normalize spaces/tabs (but keep newlines)
    - collapse >=3 newlines to 2 newlines
    - strip() at end
    """
    if not s:
        return ""
    s = s.replace("\u00ad", "")
    s = s.replace("\r", "\n").replace("\f", "\n")
    s = _RE_HYPHEN_BREAK.sub(r"\1\2", s)
    s = _RE_SPACES.sub(" ", s)
    s = _RE_MANY_NL.sub("\n\n", s)
    return s.strip()


# ----------------------------
# Normalization (MATCH SPACE)
# ----------------------------

_RE_WS_ANY = re.compile(r"\s+")
_RE_BULLETS = re.compile(r"[•·●▪►]")
_RE_QUOTES = re.compile(r"[“”„]")
_RE_APOS = re.compile(r"[’‘´`]")
_RE_DASHES = re.compile(r"[-‐-‒–—]")


def normalize_text_match(s: str) -> str:
    """
    Aggressive normalization for matching / fuzzing only.
    DO NOT use this for offsets.
    """
    if not s:
        return ""
    s = normalize_text_offset(s)
    s = s.lower()
    s = _RE_WS_ANY.sub(" ", s)
    s = _RE_BULLETS.sub(" ", s)
    s = _RE_QUOTES.sub('"', s)
    s = _RE_APOS.sub("'", s)
    s = _RE_DASHES.sub("-", s)
    return s.strip()


# ----------------------------
# PDF text extraction
# ----------------------------

def extract_pages_fitz_offset(pdf_path: Path) -> List[str]:
    """Extract pages via PyMuPDF and normalize into OFFSET SPACE."""
    doc = fitz.open(str(pdf_path))
    out: List[str] = []
    try:
        for i in range(doc.page_count):
            t = doc.load_page(i).get_text("text") or ""
            out.append(normalize_text_offset(t))
    finally:
        doc.close()
    return out


def extract_pages_pypdf_offset(pdf_path: Path) -> List[str]:
    """Optional fallback extractor (pypdf) normalized into OFFSET SPACE."""
    if PdfReader is None:
        return []
    reader = PdfReader(str(pdf_path))
    out: List[str] = []
    for p in reader.pages:
        try:
            t = p.extract_text() or ""
        except Exception:
            t = ""
        out.append(normalize_text_offset(t))
    return out


def merge_pages(primary: List[str], secondary: List[str]) -> List[str]:
    """Merge two page lists (offset-normalized), preferring primary when non-empty."""
    n = max(len(primary), len(secondary))
    out: List[str] = []
    for i in range(n):
        a = primary[i] if i < len(primary) else ""
        b = secondary[i] if i < len(secondary) else ""
        out.append(a if a else b)
    return out


def extract_pages_offset(pdf_path: Path, *, use_pypdf_fallback: bool = True) -> List[str]:
    """Canonical page extraction for OFFSET SPACE used by validator + pipeline patching."""
    pages_a = extract_pages_fitz_offset(pdf_path)
    if not use_pypdf_fallback:
        return pages_a
    pages_b = extract_pages_pypdf_offset(pdf_path)
    if not pages_b:
        return pages_a
    return merge_pages(pages_a, pages_b)


# ----------------------------
# Span-based text collection (OFFSET SPACE)
# ----------------------------

def clamp_span_to_pages(
    pages: List[str],
    sp1: int,
    sc: int,
    ep1: int,
    ec: int,
) -> Tuple[int, int, int, int]:
    """Clamp a 1-based (page, char) span into page boundaries (OFFSET SPACE pages[])."""
    if not pages:
        return 1, 0, 1, 0

    pages_total = len(pages)

    sp1 = int(sp1)
    ep1 = int(ep1)
    sc = int(sc)
    ec = int(ec)

    sp1 = max(1, min(sp1, pages_total))
    ep1 = max(1, min(ep1, pages_total))
    if ep1 < sp1:
        ep1 = sp1

    sc = max(0, min(sc, len(pages[sp1 - 1])))
    ec = max(0, min(ec, len(pages[ep1 - 1])))
    if ep1 == sp1 and ec < sc:
        ec = sc

    return sp1, sc, ep1, ec


def collect_text_for_span(
    pages: List[str],
    sp1: int,
    sc: int,
    ep1: int,
    ec: int,
    *,
    join_sep: str = "\n\n",
) -> str:
    """
    Collect text for a span in OFFSET SPACE.
    - pages[] must already be offset-normalized
    - sp1/ep1 are 1-based page numbers
    - sc/ec are 0-based char indices; ec is exclusive
    """
    if not pages:
        return ""

    sp1, sc, ep1, ec = clamp_span_to_pages(pages, sp1, sc, ep1, ec)

    sp = sp1 - 1
    ep = ep1 - 1

    if sp == ep:
        return normalize_text_offset(pages[sp][sc:ec])

    parts: List[str] = []
    parts.append(pages[sp][sc:])
    for p in range(sp + 1, ep):
        parts.append(pages[p])
    parts.append(pages[ep][:ec])

    return normalize_text_offset(join_sep.join(parts))


# ----------------------------
# Small helpers
# ----------------------------

def has_full_span(seg: Dict[str, Any]) -> bool:
    return (
        isinstance(seg.get("start_page"), int)
        and isinstance(seg.get("start_char"), int)
        and isinstance(seg.get("end_page"), int)
        and isinstance(seg.get("end_char"), int)
    )


# ----------------------------
# TOP marker regex (OFFSET SPACE) — single source of truth
# ----------------------------

def top_key_regex_line_start(top_key: str) -> re.Pattern:
    """
    Regex to find TOP markers at (approx.) line start in OFFSET SPACE.

    Goals:
    - tolerate variants:
        "22.3" vs "22 . 3" vs "22,3"
        "19.a" vs "19a" vs "19 a" vs "19. a"
    - avoid matching "19" for "19.a"
    - require "line start" (after \\n) to reduce false positives in running text
    """
    k = (top_key or "").strip()

    # Parse variants: <num> [.<sub>] or <num>.<letter>
    m = re.match(r"^\s*(\d+)\s*(?:[.\,]?\s*([a-zA-Z]))?\s*(?:[.\,]\s*(\d+))?\s*$", k)
    if m:
        num = m.group(1)
        suf = m.group(2)  # letter suffix
        sub = m.group(3)  # numeric sub (e.g. 22.3)
        if sub:
            key_pat = rf"{re.escape(num)}\s*[\.\,]\s*{re.escape(sub)}"
        elif suf:
            key_pat = rf"{re.escape(num)}\s*[\.\,]?\s*{re.escape(suf)}"
        else:
            key_pat = rf"{re.escape(num)}"
    else:
        esc = re.escape(k)
        esc = esc.replace(r"\.", r"\s*[\.\,]\s*")
        key_pat = esc

    pat = rf"(?mi)(^|\n)[ \t]*({key_pat})(?=[ \t]|[)\].,:;]|$)"
    return re.compile(pat)


# ----------------------------
# Anchor fragments (DocAI hint anchors)
# ----------------------------

def _anchor_fragments(hint_anchor: str, *, max_lines: int = 3, max_len: int = 160) -> List[str]:
    anc = normalize_text_offset((hint_anchor or "").strip())
    if not anc:
        return []

    lines = [ln.strip() for ln in anc.splitlines() if ln.strip()]
    frags: List[str] = []
    for ln in lines[:max_lines]:
        frags.append(ln)
        if len(ln) > max_len:
            frags.append(ln[:max_len].strip())
        elif len(ln) >= 40:
            frags.append(ln[: min(len(ln), 120)].strip())

    out: List[str] = []
    seen = set()
    for f in frags:
        f = (f or "").strip()
        if not f:
            continue
        if f not in seen:
            out.append(f)
            seen.add(f)
    return out


# ----------------------------
# Anchor -> OFFSET SPACE position (generic)
# ----------------------------

def find_anchor_offset(
    pages: List[str],
    hint_start_page1: int,
    hint_anchor: str,
    *,
    window_pages: int = 4,
    min_fragment_len: int = 20,
) -> Optional[Tuple[int, int]]:
    """
    Deterministically find (page1, char) in PDF OFFSET SPACE using DocAI hint page + anchor.
    Search: substring match for fragments within hint page ± window_pages (then expand once).
    """
    if not pages:
        return None

    n_pages = len(pages)
    p0 = max(1, min(int(hint_start_page1), n_pages))

    frags = _anchor_fragments(hint_anchor)
    frags = [f for f in frags if len(f) >= int(min_fragment_len)]
    if not frags:
        return None

    for wp in (int(window_pages), max(int(window_pages), 8)):
        lo = max(1, p0 - wp)
        hi = min(n_pages, p0 + wp)

        for p1 in range(lo, hi + 1):
            txt = pages[p1 - 1] or ""
            for f in frags:
                j = txt.find(f)
                if j != -1:
                    return (p1, max(0, j))

    return None


# ----------------------------
# TOP hint -> OFFSET SPACE position
# ----------------------------

def find_top_start_from_hint(
    pages: List[str],
    top_key: str,
    hint_start_page1: int,
    hint_anchor: str = "",
    *,
    window_pages: int = 4,
) -> Optional[Tuple[int, int]]:
    """
    Deterministically find (page1, char) in PDF OFFSET SPACE given:
    - hint_start_page1 (1-based) from DocAI
    - top_key (marker to search)
    - hint_anchor optional fallback

    Search order (within hint page ± window_pages; if not found, expand once):
    1) marker regex at line start
    2) anchor fragment substring search
    """
    if not pages:
        return None

    n_pages = len(pages)
    p0 = max(1, min(int(hint_start_page1), n_pages))

    pat = top_key_regex_line_start(top_key)
    frags = _anchor_fragments(hint_anchor)

    for wp in (int(window_pages), max(int(window_pages), 8)):
        lo = max(1, p0 - wp)
        hi = min(n_pages, p0 + wp)

        # 1) marker regex
        for p1 in range(lo, hi + 1):
            txt = pages[p1 - 1] or ""
            m = pat.search(txt)
            if m:
                start = m.start()
                if start < len(txt) and txt[start:start + 1] == "\n":
                    start += 1
                return (p1, max(0, start))

        # 2) anchor fragments
        if frags:
            for p1 in range(lo, hi + 1):
                txt = pages[p1 - 1] or ""
                for f in frags:
                    pos = txt.find(f)
                    if pos != -1:
                        return (p1, max(0, pos))

    return None