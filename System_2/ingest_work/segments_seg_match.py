#segments_seg_match.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional, Tuple

from rapidfuzz import fuzz

from segments_utils import normalize_text_match
from segments_seg_types import Candidate, PageLayoutIndex, RuleAnchor, TopItem
from segments_seg_layout import heading_bonus_for_title


def top_key_regex_anywhere(top_key: str) -> re.Pattern:
    k = (top_key or "").strip()
    if not k:
        return re.compile(r"$^")

    if re.fullmatch(r"\d+", k):
        esc = re.escape(k)
        # NICHT matchen, wenn direkt (ohne Leerzeichen) ".<zahl>" folgt (22.1 / 22.2 etc.)
        # FIX: (?!\.\d) statt (?!\s*\.\s*\d) – erlaubt "9. 2774/..." (Drucksachen-Nr.)
        return re.compile(rf"(?mi)(?<!\d){esc}(?!\d)(?!\.\d)\s*(?:[)\.:]\s*)?")

    esc = re.escape(k).replace(r"\.", r"\s*\.\s*")
    return re.compile(rf"(?mi)(?<!\w){esc}(?!\w)\s*(?:[)\.:]\s*)?")


def title_words(title: str, max_words: int = 3, min_len: int = 5) -> List[str]:
    t = (title or "").lower().replace("\u00ad", "")
    t = re.sub(r"[^a-z0-9äöüß ]+", " ", t)
    ws = [w for w in t.split() if len(w) >= min_len]
    return ws[:max_words]


def find_heading_like_title_positions(page_text: str, title: str) -> List[int]:
    if not page_text or not title:
        return []
    t = title.lower().replace("\u00ad", "")
    lines = page_text.splitlines()

    positions: List[int] = []
    offset = 0
    for ln_raw in lines:
        ln = ln_raw.rstrip("\r")
        ln_l = ln.lower()
        idx = ln_l.find(t)
        if idx != -1 and idx <= 6:
            leading_ws = len(ln_raw) - len(ln_raw.lstrip())
            positions.append(offset + leading_ws + idx)
        offset += len(ln_raw) + 1
    return positions


def looks_like_heading_followup(after: str) -> bool:
    a = after[:700]
    if re.search(r"(?i)\b\d{3,5}\s*/\s*\d{4}\b", a):
        return True
    if re.search(r"(?m)^\s*[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß \-/]{2,40}:\s+\S+", a):
        return True
    if re.search(r"(?m)^[\-_]{10,}\s*$", a):
        return True
    return False


def snap_back_to_key_line(page_text: str, pos: int, top_key: str) -> int:
    if not page_text or not top_key:
        return pos
    window_start = max(0, pos - 140)
    before = page_text[window_start:pos]
    esc = re.escape(top_key).replace(r"\.", r"\s*\.\s*")
    m = re.search(rf"(?mi)(^|\n)\s*{esc}\s*(?:[)\.:]\s*)?", before)
    if not m:
        return pos
    return window_start + m.start()


def _header_mentions_top_key(header_text: str, top_key: str) -> bool:
    """
    Matcht TOP-Key in header_text:
      - am Zeilenanfang (^|\\n)
      - nach Satzende (". ") – wichtig, da heading_text Fließtext + nächste Überschrift enthält
      - mit optionalem Seitenkopf-Prefix "- 10 -"
    Verhindert False-Positives wie "/4" in "2752/2005/4".
    """
    ht = (header_text or "").strip()
    tk = (top_key or "").strip()
    if not ht or not tk:
        return False

    tk_esc = re.escape(tk).replace(r"\.", r"\s*\.\s*")
    prefix = r"(?:[-–—]\s*\d+\s*[-–—]\s*)?"

    # Anker: Zeilenanfang ODER nach Satzende (". ") ODER nach Abschnittsmarker ("Teil ")
    anchor = r"(?:(?:^|\n)|(?<=\.)\s+)"

    if re.fullmatch(r"\d+", tk):
        # FIX: (?!\.\d) statt (?!\.\s*\d) – konsistent mit top_key_heading_regex
        pat = rf"(?mi){anchor}{prefix}{tk_esc}\s*(?!\.\d)\s*[)\.:]"
        return re.search(pat, ht) is not None

    pat = rf"(?mi){anchor}{prefix}{tk_esc}\s*(?:[)\.:]|\s)"
    return re.search(pat, ht) is not None


def top_key_heading_regex(top_key: str) -> re.Pattern:
    """
    Findet TOP-Überschriften auch mit Seitenkopf-Prefix wie "- 10 - 12. ...".

    FIX: Lookahead geaendert von r"(?!\\.\\s*\\d)" auf r"(?!\\.\\d)":
    Erlaubt "3. 2. Stufe..." (Leerzeichen zwischen Punkt und Ziffer),
    blockt weiterhin "3.2" (kein Leerzeichen).
    """
    k = (top_key or "").strip()
    if not k:
        return re.compile(r"$^")

    prefix = r"(?:[-–—]\s*\d+\s*[-–—]\s*)?"

    if re.fullmatch(r"\d+", k):
        esc = re.escape(k)
        return re.compile(
            rf"(?mi)(^|\n)\s*{prefix}{esc}\s*(?!\.\d)\s*[)\.:]\s+"
        )

    esc = re.escape(k).replace(r"\.", r"\s*\.\s*")
    return re.compile(
        rf"(?mi)(^|\n)\s*{prefix}{esc}\s*(?:[)\.:]|\s)\s+"
    )


def build_candidates_for_top(
    pages: List[str],
    layout: List[PageLayoutIndex],
    rule_anchors: List[List[RuleAnchor]],
    top: TopItem,
    start_page: int,
    end_page: int,
) -> List[Candidate]:
    cands: List[Candidate] = []
    key_pat = top_key_regex_anywhere(top.key)
    heading_pat = top_key_heading_regex(top.key)
    twords = title_words(top.title, max_words=3, min_len=5)
    title_m = normalize_text_match(top.title).lower() if top.title else ""

    # --- Heading-line candidates (robust): TOP steht als Zeilenanfang (ggf. "- 10 - 12.") ---
    for p in range(start_page, end_page + 1):
        if p < 0 or p >= len(pages):
            continue
        txt = pages[p] or ""
        if not txt:
            continue

        for m in heading_pat.finditer(txt):
            pos = m.start()
            bonus, _dbg = heading_bonus_for_title(layout[p], top.title)

            s = 95.0 + bonus

            # FIX: pos<200-Bonus entfernt (begünstigte Sub-Items am Seitenanfang).
            # Stattdessen: Reporter-Signal innerhalb 400 Zeichen = echter TOP-Heading.
            # "Berichterstatter:" (2006er Format) oder "Bericht erstattet:" (2025er Format)
            after_ctx = txt[m.end():m.end() + 400]
            if re.search(r"(?i)berichterstatter|bericht\s+erstattet", after_ctx):
                s += 20.0

            if rule_anchors and p < len(rule_anchors) and rule_anchors[p]:
                s += 2.0

            cands.append(Candidate(page=p, char=int(pos), score=float(s), method="heading_line"))

    # --- Rule-based candidates ---
    for p in range(start_page, end_page + 1):
        if p < 0 or p >= len(pages):
            continue
        if p >= len(rule_anchors):
            continue

        txt = pages[p] or ""
        if not txt:
            continue

        for ra in rule_anchors[p]:
            hdr = ra.header_text or ""
            if not hdr:
                continue

            key_hit = _header_mentions_top_key(hdr, top.key)

            s_hdr_title = 0.0
            if title_m:
                s_hdr_title = float(fuzz.partial_ratio(title_m, normalize_text_match(hdr).lower()))

            # require key OR weaker title match
            if (not key_hit) and (s_hdr_title < 65.0):
                continue

            all_heading_matches = list(heading_pat.finditer(txt))
            if all_heading_matches:
                # Wähle den Match, dessen char-Position am nächsten an ra.y/page_height liegt
                page_len = max(1, len(txt))
                target_char = int((ra.y / 800.0) * page_len)  # 800pt = typische Seitenhöhe
                m0 = min(all_heading_matches, key=lambda m: abs(m.start() - target_char))
                pos = m0.start()
            else:
                all_key_matches = list(key_pat.finditer(txt[:7000]))
                if all_key_matches:
                    page_len = max(1, len(txt))
                    target_char = int((ra.y / 800.0) * page_len)
                    m1 = min(all_key_matches, key=lambda m: abs(m.start() - target_char))
                    pos = m1.start()
                else:
                    pos = 0

            bonus, _dbg = heading_bonus_for_title(layout[p], top.title)

            s = 55.0 + (18.0 if key_hit else 0.0) + (0.9 * s_hdr_title) + bonus
            if pos < 120:
                s += 4.0
            if pos < 50:
                s += 2.0

            cands.append(Candidate(page=p, char=int(pos), score=float(s), method="rule_header"))

    # --- Existing key/title candidates ---
    for p in range(start_page, end_page + 1):
        txt = pages[p]
        if not txt:
            continue

        for m in key_pat.finditer(txt):
            pos = m.start()

            after_raw = txt[pos:pos + 520]
            after_m = normalize_text_match(after_raw).lower()

            if re.search(r"(?i)\b\d{3,5}\s*/\s*\d{4}\b", after_raw[:260]):
                if title_m:
                    ctx_probe = normalize_text_match(txt[max(0, pos - 200):min(len(txt), pos + 900)]).lower()
                    s_title_probe = float(fuzz.partial_ratio(title_m, ctx_probe))
                    if s_title_probe < 55:
                        bonus, _dbg = heading_bonus_for_title(layout[p], top.title)
                        s2 = 10.0 + bonus
                        if pos < 80:
                            s2 -= 6.0
                        cands.append(Candidate(page=p, char=pos, score=s2, method="key_only_weak"))
                        continue

            gate_ok = True
            if twords:
                if not any(w in after_m for w in twords):
                    gate_ok = False

            s_title = 0.0
            if title_m:
                ctx = normalize_text_match(txt[max(0, pos - 200):min(len(txt), pos + 900)]).lower()
                s_title = float(fuzz.partial_ratio(title_m, ctx))

            bonus, _dbg = heading_bonus_for_title(layout[p], top.title)

            if gate_ok and (not title_m or s_title >= 70):
                s2 = 40.0 + s_title + bonus
                method = "key_title"
            else:
                s2 = 22.0 + (0.35 * s_title) + (0.5 * bonus)
                method = "key_only"

            if pos < 80:
                s2 -= 6.0

            cands.append(Candidate(page=p, char=pos, score=s2, method=method))

    # --- Title heading fallback ---
    if not cands and top.title:
        for p in range(start_page, end_page + 1):
            txt = pages[p]
            if not txt:
                continue
            poss = find_heading_like_title_positions(txt, top.title)
            for pos in poss:
                after = txt[pos:pos + 1200]
                if not looks_like_heading_followup(after):
                    continue

                pos2 = snap_back_to_key_line(txt, pos, top.key)

                base = float(
                    fuzz.partial_ratio(
                        normalize_text_match(top.title).lower(),
                        normalize_text_match(after[:900]).lower()
                    )
                )
                bonus, _dbg = heading_bonus_for_title(layout[p], top.title)

                s2 = 20.0 + base + bonus
                if pos2 != pos:
                    s2 += 6.0

                cands.append(Candidate(page=p, char=pos2, score=s2, method="title_heading"))

    # --- Title-only fallback near page head ---
    if not cands and top.title:
        title_l = normalize_text_match(top.title).lower()
        for p in range(start_page, end_page + 1):
            txt = pages[p]
            if not txt:
                continue
            head = normalize_text_match(txt[:3500]).lower()
            s2 = float(fuzz.partial_ratio(title_l, head))
            if s2 >= 90:
                bonus, _dbg = heading_bonus_for_title(layout[p], top.title)
                cands.append(Candidate(page=p, char=0, score=20.0 + s2 + bonus, method="title_only"))

    # --- Last-resort key-only fallback ---
    if not cands:
        for p in range(start_page, end_page + 1):
            txt = pages[p]
            if not txt:
                continue
            m = key_pat.search(txt)
            if m:
                bonus, _dbg = heading_bonus_for_title(layout[p], top.title)
                cands.append(Candidate(page=p, char=m.start(), score=35.0 + bonus, method="key_only"))
                break

    cands.sort(key=lambda x: x.score, reverse=True)
    return cands[:60]


def monotone_ok(prev: Candidate, cur: Candidate) -> bool:
    if cur.page > prev.page:
        return True
    if cur.page == prev.page and cur.char > prev.char:
        return True
    return False


def dp_choose_best_path(all_candidates: List[List[Candidate]], base_start: Candidate) -> List[Optional[Candidate]]:
    n = len(all_candidates)
    if n == 0:
        return []

    # FIX: TOPs ohne Kandidaten werden übersprungen.
    # Der DP läuft nur über nicht-leere Ebenen; Lücken (z.B. TOP 19.a) unterbrechen
    # die Kette nicht mehr.
    non_empty: List[Tuple[int, List[Candidate]]] = [
        (i, cands) for i, cands in enumerate(all_candidates) if cands
    ]

    if not non_empty:
        return [None] * n

    ne_n = len(non_empty)
    ne_dp: List[Dict[int, float]] = []
    ne_par: List[Dict[int, Optional[int]]] = []

    # Erste nicht-leere Ebene
    dp0: Dict[int, float] = {}
    par0: Dict[int, Optional[int]] = {}
    for j, c in enumerate(non_empty[0][1]):
        if monotone_ok(base_start, c):
            dp0[j] = c.score
            par0[j] = -1
    ne_dp.append(dp0)
    ne_par.append(par0)

    for ni in range(1, ne_n):
        _, cur_list = non_empty[ni]
        _, prev_list = non_empty[ni - 1]

        dpi: Dict[int, float] = {}
        pari: Dict[int, Optional[int]] = {}

        for j, cur in enumerate(cur_list):
            best_val = None
            best_k = None

            if not ne_dp[ni - 1]:
                if monotone_ok(base_start, cur):
                    best_val = cur.score
                    best_k = -1

            for k, prev in enumerate(prev_list):
                if k not in ne_dp[ni - 1]:
                    continue
                if not monotone_ok(prev, cur):
                    continue

                prev_score = ne_dp[ni - 1][k]
                jump_penalty = 0.0
                if cur.page == prev.page:
                    jump_penalty += 0.6
                else:
                    jump_penalty += 0.25 * (cur.page - prev.page)

                val = prev_score + cur.score - jump_penalty
                if best_val is None or val > best_val:
                    best_val = val
                    best_k = k

            if best_val is not None:
                dpi[j] = best_val
                pari[j] = best_k

        # Wenn leer: Fallback auf base_start (Kette nicht brechen)
        if not dpi:
            for j, cur in enumerate(cur_list):
                if monotone_ok(base_start, cur):
                    dpi[j] = cur.score
                    pari[j] = -1

        ne_dp.append(dpi)
        ne_par.append(pari)

    # Backtrace über nicht-leere Ebenen — mit Restart-Unterstützung
    # (sonst liefert der Backtrace nur den letzten zusammenhängenden Block)
    path: List[Optional[Candidate]] = [None] * n

    ni = ne_n - 1
    while ni >= 0:
        # nächste Ebene rückwärts suchen, die DP-Zustände hat
        while ni >= 0 and not ne_dp[ni]:
            ni -= 1
        if ni < 0:
            break

        # neuen Block starten: bestes j dieser Ebene
        j = max(ne_dp[ni].items(), key=lambda kv: kv[1])[0]

        # Elternkette zurücklaufen, bis Restart (-1) oder Ende
        while ni >= 0:
            orig_i, cands = non_empty[ni]
            path[orig_i] = cands[j]

            pj = ne_par[ni].get(j, -1)

            ni -= 1
            if pj == -1:
                break
            j = pj

    return path
