#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
KommRAG – synonyms.py
Synonym-Cache mit LLM-Fallback (Mistral).

Flow:
  1. Query-Terme extrahieren (Content-Nouns, keine Stopwörter).
  2. Pro Term: DB-Lookup in rag.synonyms.
  3. Cache-Miss → Mistral generiert Varianten → Cache-Write.
  4. Ergebnis: dict {term: [variants, ...]}.

Zusätzlich:
  - expand_query_for_fts(query)   → Query mit OR-Klauseln für websearch_to_tsquery
  - expand_query_variants(query)  → Liste paraphrasierter Query-Varianten für Dense-Retrieval
"""

import os
import re
import json
import logging
from typing import Iterable

import psycopg2
import psycopg2.extras
from dotenv import load_dotenv
from mistralai import Mistral

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(SCRIPT_DIR, ".env"), override=True)

DSN = os.environ["KOMMRAG_DSN"]
MISTRAL_KEY = os.environ["MISTRAL_API_KEY"]

SYNONYM_MODEL = "mistral-small-latest"

log = logging.getLogger("kommrag-synonyms")

# ─────────────────────────────────────────────
# Stopwörter (Auszug – erweiterbar)
# ─────────────────────────────────────────────

GERMAN_STOPWORDS = {
    "der", "die", "das", "den", "dem", "des",
    "ein", "eine", "einer", "einem", "einen", "eines",
    "und", "oder", "aber", "auch", "noch", "nicht", "nur", "schon",
    "ist", "sind", "war", "waren", "wird", "werden", "worden",
    "hat", "haben", "hatte", "hatten",
    "für", "von", "zu", "zur", "zum", "in", "im", "an", "am", "auf",
    "über", "unter", "bei", "mit", "nach", "vor", "ohne", "um",
    "wer", "was", "wie", "wann", "wo", "warum", "welche", "welcher", "welches",
    "ich", "du", "er", "sie", "es", "wir", "ihr", "mich", "dich",
    "mir", "dir", "ihm", "uns", "euch", "ihnen",
    "als", "wenn", "weil", "dass", "ob",
    "alle", "alles", "einige", "manche", "viele", "wenige",
    "sehr", "mehr", "weniger", "etwa", "circa",
    "ratsprotokoll", "protokoll", "protokolle", "sitzung", "sitzungen",
    "ausschuss", "bezirksvertretung", "gremium", "gremien",
    "stadt", "essen", "beschluss", "beschlüsse",
    "jahr", "jahre", "zuletzt", "seit", "dabei", "dazu",
}

# Endungen, die häufig auf Substantive hinweisen
NOUN_SUFFIXES = (
    "ung", "heit", "keit", "schaft", "ität", "tion", "sion",
    "nis", "tum", "chen", "lein",
)

# Minimale Länge für einen Query-Term
MIN_TERM_LEN = 5

# Maximale Zahl an Termen, für die wir Expansion betreiben
MAX_TERMS_PER_QUERY = 3

# Maximale Zahl an Varianten pro Term (vom LLM)
MAX_VARIANTS_PER_TERM = 5

# Maximale Zahl an paraphrasierten Query-Varianten
MAX_QUERY_PARAPHRASES = 2


# ─────────────────────────────────────────────
# Term-Extraktion
# ─────────────────────────────────────────────

def extract_content_terms(query: str) -> list[str]:
    """Extrahiert Content-Terme aus einer Query für Synonym-Expansion."""
    tokens = re.findall(r"[A-Za-zÄÖÜäöüß\-]+", query)
    out = []
    seen = set()

    for tok in tokens:
        low = tok.lower()

        if len(low) < MIN_TERM_LEN:
            continue
        if low in GERMAN_STOPWORDS:
            continue
        if low in seen:
            continue

        # Eigennamen (Großschreibung nicht am Satzanfang) oder Substantive
        is_capital = tok[0].isupper()
        has_noun_suffix = any(low.endswith(s) for s in NOUN_SUFFIXES)
        is_compound = len(low) >= 8  # lange Wörter sind oft Komposita

        if is_capital or has_noun_suffix or is_compound:
            out.append(low)
            seen.add(low)

        if len(out) >= MAX_TERMS_PER_QUERY:
            break

    return out


# ─────────────────────────────────────────────
# Cache-Zugriff
# ─────────────────────────────────────────────

def _get_cached(cur, terms: list[str]) -> dict[str, list[str]]:
    if not terms:
        return {}

    cur.execute(
        "SELECT term, variants FROM rag.synonyms WHERE term = ANY(%s)",
        (terms,),
    )
    return {row["term"]: list(row["variants"] or []) for row in cur.fetchall()}


def _bump_hit_counts(cur, terms: list[str]) -> None:
    if not terms:
        return
    cur.execute(
        """
        UPDATE rag.synonyms
        SET hit_count = hit_count + 1,
            updated_at = now()
        WHERE term = ANY(%s)
        """,
        (terms,),
    )


def _store_variants(cur, term: str, variants: list[str], source: str = "llm") -> None:
    cur.execute(
        """
        INSERT INTO rag.synonyms (term, variants, source, hit_count)
        VALUES (%s, %s, %s, 1)
        ON CONFLICT (term) DO UPDATE
          SET variants   = EXCLUDED.variants,
              source     = CASE
                             WHEN rag.synonyms.source = 'manual' THEN rag.synonyms.source
                             ELSE EXCLUDED.source
                           END,
              updated_at = now()
        """,
        (term, variants, source),
    )


# ─────────────────────────────────────────────
# LLM-Expansion (Fallback)
# ─────────────────────────────────────────────

def _llm_expand_terms(terms: list[str]) -> dict[str, list[str]]:
    """
    Fragt Mistral nach Synonymen/verwandten Begriffen für mehrere Terme.
    Gibt {term: [variants]} zurück. Fehler -> leeres Dict für betroffene Terme.
    """
    if not terms:
        return {}

    prompt = (
        "Für jeden der folgenden deutschen Begriffe aus einem kommunalpolitischen Kontext "
        "liefere 3 bis 5 semantisch verwandte Varianten (Synonyme, Komposita-Varianten, "
        "alternative Schreibweisen, verwandte Fachbegriffe). "
        "Antworte ausschließlich mit validem JSON im Format:\n"
        '{"term1": ["variante1", "variante2", ...], "term2": [...]}\n\n'
        "Keine Erklärungen, kein Markdown, nur das JSON.\n"
        "Varianten alle kleingeschrieben, ohne Bindestriche außer wenn typisch "
        "(z.B. 's-bahn'). Keine Wiederholung des Original-Terms.\n\n"
        f"Begriffe: {json.dumps(terms, ensure_ascii=False)}"
    )

    try:
        client = Mistral(api_key=MISTRAL_KEY)
        resp = client.chat.complete(
            model=SYNONYM_MODEL,
            messages=[{"role": "user", "content": prompt}],
            max_tokens=400,
            response_format={"type": "json_object"},
        )
        text = resp.choices[0].message.content.strip()
        data = json.loads(text)
    except Exception as e:
        log.warning(f"LLM-Synonym-Expansion fehlgeschlagen: {e}")
        return {t: [] for t in terms}

    out: dict[str, list[str]] = {}
    for term in terms:
        variants = data.get(term, [])
        if not isinstance(variants, list):
            out[term] = []
            continue
        cleaned = []
        for v in variants[:MAX_VARIANTS_PER_TERM]:
            if not isinstance(v, str):
                continue
            v = v.strip().lower()
            if v and v != term and v not in cleaned:
                cleaned.append(v)
        out[term] = cleaned

    return out


# ─────────────────────────────────────────────
# Öffentliche API
# ─────────────────────────────────────────────

def get_synonyms(query: str) -> dict[str, list[str]]:
    """
    Liefert {term: [variants]} für eine Query.
    Nutzt Cache, füllt Lücken via LLM, persistiert Ergebnisse.
    """
    terms = extract_content_terms(query)
    if not terms:
        return {}

    conn = psycopg2.connect(DSN)
    conn.autocommit = True
    cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)

    try:
        cached = _get_cached(cur, terms)
        missing = [t for t in terms if t not in cached]

        if cached:
            _bump_hit_counts(cur, list(cached.keys()))

        if missing:
            generated = _llm_expand_terms(missing)
            for term, variants in generated.items():
                _store_variants(cur, term, variants, source="llm")
                cached[term] = variants

        return {t: cached.get(t, []) for t in terms}

    finally:
        cur.close()
        conn.close()


def flatten_variants(synonyms: dict[str, list[str]]) -> list[str]:
    """Alle Varianten als flache Liste (deduped, ohne leere Einträge)."""
    out = []
    seen = set()
    for variants in synonyms.values():
        for v in variants:
            if v and v not in seen:
                out.append(v)
                seen.add(v)
    return out


def expand_query_for_fts(query: str, synonyms: dict[str, list[str]]) -> str:
    """
    Erweitert die Original-Query um OR-verknüpfte Synonyme für websearch_to_tsquery.

    Beispiel:
      "Was wurde zur Taubenproblematik beschlossen?"
      + synonyms {'taubenproblematik': ['taubencontainer', 'stadttauben']}
      → 'Was wurde zur (Taubenproblematik OR taubencontainer OR stadttauben) beschlossen?'
    """
    if not synonyms:
        return query

    result = query
    for term, variants in synonyms.items():
        if not variants:
            continue
        # Case-insensitive Ersetzung des Terms durch OR-Gruppe
        pattern = re.compile(rf"\b{re.escape(term)}\b", re.IGNORECASE)
        or_group = "(" + " OR ".join([term] + variants) + ")"

        new_result, n = pattern.subn(or_group, result, count=1)
        if n > 0:
            result = new_result

    return result


# ─────────────────────────────────────────────
# Query-Paraphrasierung für Dense-Retrieval
# ─────────────────────────────────────────────

def generate_query_paraphrases(query: str, synonyms: dict[str, list[str]]) -> list[str]:
    """
    Erzeugt bis zu N paraphrasierte Query-Varianten, indem Terme durch ihre
    jeweils häufigsten Synonyme ersetzt werden. Rein deterministisch, kein
    weiterer LLM-Call nötig (die Synonyme sind bereits da).

    Ergebnis: Liste ohne die Original-Query selbst.
    """
    if not synonyms:
        return []

    variants_per_term = [(t, vs) for t, vs in synonyms.items() if vs]
    if not variants_per_term:
        return []

    paraphrases = []
    for i in range(MAX_QUERY_PARAPHRASES):
        q = query
        any_replaced = False
        for term, vs in variants_per_term:
            if i >= len(vs):
                continue
            replacement = vs[i]
            pattern = re.compile(rf"\b{re.escape(term)}\b", re.IGNORECASE)
            new_q, n = pattern.subn(replacement, q, count=1)
            if n > 0:
                q = new_q
                any_replaced = True

        if any_replaced and q != query and q not in paraphrases:
            paraphrases.append(q)

    return paraphrases
