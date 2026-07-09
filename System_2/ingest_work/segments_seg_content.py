#segments_seg_content.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import List, Optional, Tuple

from segments_utils import normalize_text_offset
from segments_seg_types import TopItem


def load_agenda(agenda_path: Path) -> List[TopItem]:
    data = json.loads(agenda_path.read_text(encoding="utf-8"))
    items: List[TopItem] = []

    def add_items(raw_list: List[object]) -> None:
        for it in raw_list:
            if not isinstance(it, dict):
                continue
            key = (it.get("top_key_curated") or it.get("top_key") or it.get("key") or "").strip()
            title = (it.get("title_curated") or it.get("title") or it.get("name") or "").strip()
            if key:
                items.append(TopItem(key=key, title=title))

    if isinstance(data, dict):
        if isinstance(data.get("agenda"), list):
            add_items(data["agenda"])
        elif isinstance(data.get("items"), list):
            add_items(data["items"])
        else:
            for k in ["tops", "tagesordnung", "points", "tagesordnungspunkte"]:
                if isinstance(data.get(k), list):
                    add_items(data[k])
                    break
    elif isinstance(data, list):
        add_items(data)

    return items


def looks_like_agenda_page(text: str) -> bool:
    if not text:
        return False
    if not re.search(r"(?mi)^\s*tagesordnung\b(?!spunkt)", text):
        return False

    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    if len(lines) < 8:
        return False
    short = sum(1 for ln in lines if len(ln) < 40)
    listy = (short / max(1, len(lines))) >= 0.55

    m = re.search(r"(?mis)^\s*a\s*\.?\s*\n?\s*öffentlicher\s+teil\b", text)
    if m:
        after = text[m.end():]
        off = first_flowtext_offset(after)
        if off is not None:
            return False
        # Kein Fließtext nach "Öffentlicher Teil": Agenda wenn TOP-Nummern vorhanden
        top_count = len(re.findall(r"(?m)^\s*\d+[\.\)]\s+\S", after))
        return top_count >= 3 or listy

    m2 = re.search(r"(?mis)^\s*b\s*\.?\s*\n?\s*nicht\s+öffentlicher\s+teil\b", text)
    if m2:
        after = text[m2.end():]
        off = first_flowtext_offset(after)
        if off is not None:
            return False
        # Kein Fließtext nach "Nicht öffentlicher Teil": Agenda wenn TOP-Nummern vorhanden
        top_count = len(re.findall(r"(?m)^\s*\d+[\.\)]\s+\S", after))
        return top_count >= 3 or listy

    return listy


def first_flowtext_offset(text: str) -> Optional[int]:
    if not text:
        return None
    t = text.replace("\u00ad", "")
    parts = re.split(r"\n{2,}", t)
    cursor = 0
    for p in parts:
        p_stripped = p.strip()
        if not p_stripped:
            cursor += len(p) + 2
            continue
        if len(p_stripped) < 250:
            cursor += len(p) + 2
            continue
        sent = len(re.findall(r"[.!?](?:\s|\n|$)", p_stripped))
        if sent < 2:
            cursor += len(p) + 2
            continue
        lines = [ln.strip() for ln in p_stripped.splitlines() if ln.strip()]
        short = sum(1 for ln in lines if len(ln) < 35)
        if lines and (short / len(lines)) > 0.55:
            cursor += len(p) + 2
            continue
        # Nummerierte Tagesordnungslisten sind kein Fließtext
        listy_lines = len(re.findall(r"(?m)^\s*\d+[\.\)]\s+\S", p_stripped))
        if listy_lines / max(1, len(lines)) >= 0.20:
            cursor += len(p) + 2
            continue
        return cursor
    return None


def detect_content_start_by_flowtext(pages: List[str], debug: bool = False) -> Tuple[int, int, int]:
    last_agenda = -1
    agenda_pages = []
    for i, txt in enumerate(pages):
        if looks_like_agenda_page(txt):
            last_agenda = i
            agenda_pages.append(i + 1)

    if debug:
        print(f"[content_start] looks_like_agenda_page: seiten={agenda_pages}, last_agenda={last_agenda} (seite {last_agenda + 1})")

    start_page = min(len(pages) - 1, max(0, last_agenda + 1))

    if debug:
        print(f"[content_start] start_page={start_page} (seite {start_page + 1})")

    re_pub = re.compile(r"(?mis)^\s*a\s*\.?\s*\n?\s*öffentlicher\s+teil\b")
    re_pub2 = re.compile(r"(?mis)^\s*öffentlicher\s+teil\b")

    for p in range(start_page, len(pages)):
        txt = pages[p] or ""
        m = re_pub.search(txt) or re_pub2.search(txt)
        if m:
            start_char = m.start()

            pre = txt[:m.start()]
            off_pre = first_flowtext_offset(pre)
            if off_pre is not None:
                start_char = off_pre

            agenda_end_page = p
            content_start_page = p
            content_start_char = start_char
            if debug:
                print(f"[content_start] via 'öffentlicher teil': agenda_end={agenda_end_page + 1}, content_start=seite {content_start_page + 1} char {content_start_char}")
            return agenda_end_page, content_start_page, content_start_char

    for p in range(start_page, len(pages)):
        off = first_flowtext_offset(pages[p])
        if off is not None:
            agenda_end_page = max(0, p - 1)
            if debug:
                print(f"[content_start] via flowtext: agenda_end={agenda_end_page + 1}, content_start=seite {p + 1} char {off}")
            return agenda_end_page, p, off

    if debug:
        print(f"[content_start] FALLBACK: seite 1 char 0")
    return 0, 0, 0


def find_inline_appendix_offset(text: str, min_fraction: float = 0.45) -> Optional[int]:
    if not text:
        return None

    start = int(len(text) * min_fraction)
    tail = text[start:]

    patterns = [
        r"(?mi)^\s*anlage\s+\d+\s+zur\s+niederschrift\b",
        r"(?mi)^\s*anlage\s+\d+\b",
        r"(?mi)^\s*anhang\b",
        r"(?mi)^\s*anlage\b",
    ]

    best: Optional[int] = None
    for pat in patterns:
        m = re.search(pat, tail)
        if m:
            off = start + m.start()
            if best is None or off < best:
                best = off

    m_top = re.search(r"(?mi)^\s*top\s+\d+\b", tail)
    if m_top:
        near = tail[m_top.start():m_top.start() + 600]
        if re.search(r"(?i)\banlage\b|\bzur\s+niederschrift\b|\banhang\b", near):
            off = start + m_top.start()
            if best is None or off < best:
                best = off

    return best


def find_nachwort_marker_offset(text: str, min_fraction: float = 0.45) -> Optional[int]:
    if not text:
        return None
    start = int(len(text) * min_fraction)
    tail = text[start:]

    patterns = [
        r"da\s+keine\s+weiteren\s+wortmeldungen\s+mehr\s+vorliegen",
        r"dankt\s+.*\s+und\s+schließt\s+die\s+sitzung",
        r"schließt\s+die\s+sitzung\s+.*\s+um\s+\d{1,2}[.:]\d{2}",
        r"die\s+sitzung\s+wird\s+.*\s+um\s+\d{1,2}[.:]\d{2}",
        r"ende\s+der\s+sitzung",
    ]
    best = None
    for pat in patterns:
        m = re.search(pat, tail, flags=re.IGNORECASE)
        if m:
            off = start + m.start()
            if best is None or off < best:
                best = off
    return best


def absolute_pos_from_span_offset(
    pages: List[str],
    sp1: int,
    sc: int,
    ep1: int,
    ec: int,
    offset_in_joined_text: int,
    join_sep: str = "\n\n",
) -> Optional[Tuple[int, int]]:
    if not pages:
        return None

    sep_len = len(join_sep)
    remaining = int(offset_in_joined_text)

    sp = max(1, min(int(sp1), len(pages)))
    ep = max(1, min(int(ep1), len(pages)))
    if ep < sp:
        ep = sp

    for p1 in range(sp, ep + 1):
        txt = pages[p1 - 1]

        if p1 == sp and p1 == ep:
            chunk = txt[max(0, sc):min(len(txt), ec)]
            if remaining <= len(chunk):
                return p1, max(0, sc) + remaining
            return None

        if p1 == sp:
            chunk_len = len(txt[max(0, sc):])
        elif p1 == ep:
            chunk_len = len(txt[:min(len(txt), ec)])
        else:
            chunk_len = len(txt)

        if remaining <= chunk_len:
            if p1 == sp:
                return p1, max(0, sc) + remaining
            return p1, remaining

        remaining -= chunk_len

        if p1 != ep:
            if remaining <= sep_len:
                return min(ep, p1 + 1), 0
            remaining -= sep_len

    return None


def detect_appendix_start_page(pages: List[str], start_search: int) -> Optional[int]:
    def first_meaningful_lines(txt: str, n: int = 10) -> List[str]:
        lines = [ln.strip() for ln in (txt or "").splitlines() if ln.strip()]
        # typische Seitenzahl-Zeilen rauswerfen
        lines = [ln for ln in lines if not re.fullmatch(r"[-–—]?\s*\d+\s*[-–—]?", ln)]
        return lines[:n]

    # --- Keyword-Header (wie vorher) ---
    re_anlage = re.compile(r"(?i)^anlage\s+\d+\b")
    re_anlage_zur = re.compile(r"(?i)^anlage\s+\d+\s+zur\s+niederschrift\b")
    re_anhang = re.compile(r"(?i)^anhang\b")

    def keyword_header_hit(i: int) -> bool:
        txt = pages[i] or ""
        top = first_meaningful_lines(txt, n=12)
        if not top:
            return False

        # nur kurze Kandidaten ganz oben
        candidates = [ln for ln in top[:3] if len(ln) <= 80]
        for ln in candidates:
            if re_anlage_zur.match(ln):
                return True
            if re_anhang.match(ln):
                return True
            if re_anlage.match(ln):
                # False positives vermeiden
                if re.search(r"(?i)\bals\s+anlage\b", ln):
                    continue
                if re.search(r"[.!?]\s*$", ln):
                    continue
                return True
        return False

    # --- Footer-Heuristik (text-only): findet Seitenfuß/Seitennummern ---
    # Ziel: "Footer present" im Protokoll-Teil, "Footer absent" im Anhang (oft Scans/Anlagen)
    re_footer_page = re.compile(r"(?mi)\bseite\s*\d+\b")
    re_footer_dashnum = re.compile(r"(?m)^\s*[-–—]\s*\d+\s*[-–—]\s*$")
    re_footer_bare = re.compile(r"(?m)^\s*\d{1,4}\s*$")

    def footer_present(i: int) -> bool:
        txt = pages[i] or ""
        if not txt:
            return False
        tail_lines = (txt.splitlines()[-35:])  # nur hinten schauen
        tail = "\n".join(tail_lines)

        # typische Muster: "Seite 12", "- 12 -", oder eine nackte Zahl als letzte Zeile
        if re_footer_page.search(tail):
            return True
        if re_footer_dashnum.search(tail):
            return True

        # nackte Zahl nur als Footer werten, wenn sie sehr weit unten steht (letzte 5 Zeilen)
        last5 = "\n".join(tail_lines[-5:])
        if re_footer_bare.search(last5):
            return True

        return False

    # --- Fenster-basierte Transition: vorher Footer stabil da, danach stabil weg ---
    W = 8
    pre_need = 0.7     # mind 70% der Seiten im pre-window haben Footer
    post_max = 0.2     # max 20% im post-window haben Footer

    s = max(0, start_search)
    if len(pages) < (s + W + 2):
        # zu kurz: nur Keywords prüfen
        for i in range(s, len(pages)):
            if keyword_header_hit(i):
                return i
        return None

    fp = [footer_present(i) for i in range(len(pages))]

    # 1) Harte Keywords zuerst (schnell & sicher)
    for i in range(s, len(pages)):
        if keyword_header_hit(i):
            return i

    # 2) Sonst: Footer-Transition suchen, aber zusätzlich Plausibilität:
    #    ab Startseite sollte nicht "leer" sein
    end = len(pages) - W - 1
    for i in range(s + W, end):
        pre = fp[i - W:i]
        post = fp[i:i + W]
        pre_ratio = sum(pre) / W
        post_ratio = sum(post) / W

        if pre_ratio >= pre_need and post_ratio <= post_max:
            # Plausibilität: ab i kommt noch Inhalt
            txt_len = sum(len(pages[k] or "") for k in range(i, min(len(pages), i + 3)))
            if txt_len >= 400:
                return i

    return None
