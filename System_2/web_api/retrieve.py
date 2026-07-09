#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
KommRAG – retrieve.py
Phase 5: Hybrid Retrieval + Suchmodi + Synonym-Expansion + Dokument-Diversität

Änderungen ggü. Phase 4:
- search_mode: 'relevant' | 'recent' | 'breadth'
- Synonym-Cache (rag.synonyms) mit LLM-Fallback
- Multi-Query Dense Retrieval (Original + Paraphrasen via RRF)
- Expanded FTS: OR-verknüpfte Synonyme in websearch_to_tsquery
- Doc-Cap pro Modus (max. N Chunks aus demselben Dokument)
"""

import os
import re
import json
import argparse
import time
from datetime import date, datetime

import psycopg2
import psycopg2.extras
from dotenv import load_dotenv
from mistralai import Mistral

from synonyms import (
    get_synonyms,
    flatten_variants,
    expand_query_for_fts,
    generate_query_paraphrases,
)

# ─────────────────────────────────────────────
# Konfiguration
# ─────────────────────────────────────────────

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(SCRIPT_DIR, ".env"), override=True)

DSN = os.environ["KOMMRAG_DSN"]
MISTRAL_KEY = os.environ["MISTRAL_API_KEY"]

EMBED_MODEL = "mistral-embed"

CANDIDATES_BASE = 40
CANDIDATES_HIGH = 140
CANDIDATES_BREADTH = 200

RRF_K_DENSE = 60
RRF_K_SPARSE = 40
RRF_K_TRIGRAM = 45
RRF_K_BACKFILL = 45
RRF_K_PARAPHRASE = 70

RECENCY_HALF_LIFE_DAYS = 365

BACKFILL_N_DOCS = 8
BACKFILL_N_ROWS = 30
BACKFILL_MAX_TERMS = 2

STRUCTURED_DOC_BOOST = 0.08
STRUCTURED_RESULT_BASE = 0.10

PROPER_NOUN_SUFFIXES = (
    "straße", "strasse", "weg", "platz", "allee", "gasse", "ring",
    "hütte", "haus", "hof", "park", "bad", "berg", "feld", "tal",
    "schule", "kirche", "zentrum", "halle", "brücke",
)

RECENCY_SIGNAL_WORDS = r"aktuell|derzeit|stand|noch|geplant|laufend|in auftrag|zuletzt|letzte|neueste|heute|jetzt|aktueller"

FACTION_ALIASES = {
    "grüne": ["grünen", "bündnis", "grüne", "gruene"],
    "cdu": ["cdu"],
    "spd": ["spd"],
    "fdp": ["fdp"],
    "linke": ["linke", "linken", "pds", "dkp"],
    "afd": ["afd"],
    "fw": ["ebb", "freie wähler", "fw"],
    "volt": ["volt", "partei", "gruppe volt & die partei"],
}


# ─────────────────────────────────────────────
# Search-Mode Profile
# ─────────────────────────────────────────────

SEARCH_MODE_PROFILES = {
    "relevant": {
        "recency_boost": 0.05,
        "recency_mult_if_current": 4.0,
        "doc_cap": 3,
        "cand_multiplier": 1.0,
    },
    "recent": {
        "recency_boost": 0.25,
        "recency_mult_if_current": 2.0,
        "doc_cap": 3,
        "cand_multiplier": 1.0,
    },
    "breadth": {
        "recency_boost": 0.0,
        "recency_mult_if_current": 1.0,
        "doc_cap": 2,
        "cand_multiplier": 1.5,
    },
}


def get_mode_profile(mode: str | None) -> dict:
    return SEARCH_MODE_PROFILES.get(mode or "relevant", SEARCH_MODE_PROFILES["relevant"])


# ─────────────────────────────────────────────
# Query-Typ-Erkennung
# ─────────────────────────────────────────────

def is_attendance_query(query: str) -> bool:
    q = query.lower()
    attendance_patterns = [
        r"\banwesend\b", r"\bteilgenommen\b", r"\bteilnahme\b",
        r"\bmitglied\b", r"\bmitglieder\b",
        r"\bfraktion\b", r"\brolle\b", r"\bvorsitz\b",
        r"\bwer ist\b", r"\bwer war\b",
        r"\bgehört .* zu\b",
        r"\bwelcher fraktion\b", r"\bwelche fraktion\b",
    ]
    return any(re.search(p, q) for p in attendance_patterns)


def is_person_statement_query(query: str) -> bool:
    q = query.lower()
    statement_patterns = [
        r"\bgesagt\b", r"\bgefragt\b", r"\bgeäußert\b", r"\bäußerte\b",
        r"\berklärt\b", r"\bmeinte\b", r"\bsagte\b", r"\bfragte\b",
        r"\bwies darauf hin\b", r"\bmerkte an\b", r"\bbat\b",
        r"\bwortmeldung\b", r"\bredete\b",
        r"\bhat .* gesagt\b", r"\bhat .* gefragt\b",
    ]
    return any(re.search(p, q) for p in statement_patterns)


def wants_person_metadata(query: str) -> bool:
    q = query.lower()
    metadata_patterns = [
        r"\bwer ist\b",
        r"\bwelcher fraktion\b", r"\bwelche fraktion\b",
        r"\bmitglied\b", r"\banwesend\b", r"\bteilgenommen\b",
        r"\brolle\b", r"\bvorsitz\b", r"\bgehört .* zu\b",
    ]
    return any(re.search(p, q) for p in metadata_patterns)


# ─────────────────────────────────────────────
# SQL-Filterhelper
# ─────────────────────────────────────────────

def build_document_filters(
    alias: str = "d",
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> tuple[str, list]:
    clauses = []
    params = []

    if gremium_key:
        clauses.append(f"{alias}.gremium_key = %s")
        params.append(gremium_key)

    if year_from is not None:
        clauses.append(f"EXTRACT(YEAR FROM {alias}.meeting_date) >= %s")
        params.append(year_from)

    if year_to is not None:
        clauses.append(f"EXTRACT(YEAR FROM {alias}.meeting_date) <= %s")
        params.append(year_to)

    sql = ""
    if clauses:
        sql = " AND " + " AND ".join(clauses)

    return sql, params


# ─────────────────────────────────────────────
# Embedding
# ─────────────────────────────────────────────

def embed_query(query: str) -> list[float]:
    client = Mistral(api_key=MISTRAL_KEY)
    resp = client.embeddings.create(model=EMBED_MODEL, inputs=[query])
    return resp.data[0].embedding


def embed_queries_batch(queries: list[str]) -> list[list[float]]:
    if not queries:
        return []
    client = Mistral(api_key=MISTRAL_KEY)
    resp = client.embeddings.create(model=EMBED_MODEL, inputs=queries)
    return [item.embedding for item in resp.data]


# ─────────────────────────────────────────────
# Dense Retrieval
# ─────────────────────────────────────────────

def dense_search(
    cur,
    query_vector: list[float],
    n: int,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[dict]:
    vector_str = "[" + ",".join(str(x) for x in query_vector) + "]"

    doc_filter_sql, doc_filter_params = build_document_filters(
        alias="d",
        gremium_key=gremium_key,
        year_from=year_from,
        year_to=year_to,
    )

    chunk_sql = f"""
        SELECT
            c.id                                        AS id,
            'chunk'                                     AS source,
            1 - (c.embedding <=> %s::vector)            AS score,
            c.chunk_text                                AS text,
            c.top_key,
            c.start_page,
            c.end_page,
            c.document_id,
            d.meeting_date,
            d.gremium_name,
            d.gremium_key,
            d.source_filename                           AS filename
        FROM rag.chunks c
        JOIN raw.documents d ON d.id = c.document_id
        WHERE c.embedding IS NOT NULL
          {doc_filter_sql}
        ORDER BY c.embedding <=> %s::vector
        LIMIT %s
    """
    chunk_params = [vector_str] + doc_filter_params + [vector_str, n]
    cur.execute(chunk_sql, tuple(chunk_params))
    chunk_rows = cur.fetchall()

    seg_sql = f"""
        SELECT
            s.id                                        AS id,
            'segment'                                   AS source,
            1 - (s.embedding <=> %s::vector)            AS score,
            s.text_full                                 AS text,
            s.top_key,
            s.start_page,
            s.end_page,
            s.document_id,
            d.meeting_date,
            d.gremium_name,
            d.gremium_key,
            d.source_filename                           AS filename
        FROM raw.segments s
        JOIN raw.documents d ON d.id = s.document_id
        WHERE s.embedding IS NOT NULL
          AND length(trim(coalesce(s.text_full, ''))) >= 20
          {doc_filter_sql}
        ORDER BY s.embedding <=> %s::vector
        LIMIT %s
    """
    seg_params = [vector_str] + doc_filter_params + [vector_str, n]
    cur.execute(seg_sql, tuple(seg_params))
    seg_rows = cur.fetchall()

    out = []
    for row in chunk_rows + seg_rows:
        out.append({
            "id": row["id"],
            "source": row["source"],
            "score": float(row["score"]),
            "text": row["text"] or "",
            "top_key": row["top_key"],
            "start_page": row["start_page"],
            "end_page": row["end_page"],
            "document_id": row["document_id"],
            "meeting_date": str(row["meeting_date"]) if row["meeting_date"] else None,
            "gremium_name": row["gremium_name"],
            "gremium_key": row["gremium_key"],
            "filename": row["filename"],
        })
    return out


def multi_dense_search(
    cur,
    queries: list[str],
    n: int,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[list[dict]]:
    if not queries:
        return []

    vectors = embed_queries_batch(queries)
    results = []
    for vec in vectors:
        results.append(dense_search(cur, vec, n, gremium_key, year_from, year_to))
    return results


# ─────────────────────────────────────────────
# Sparse Retrieval (FTS) – mit Synonym-Expansion
# ─────────────────────────────────────────────

def sparse_search(
    cur,
    query: str,
    n: int,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[dict]:
    doc_filter_sql, doc_filter_params = build_document_filters(
        alias="d",
        gremium_key=gremium_key,
        year_from=year_from,
        year_to=year_to,
    )

    # Doc-Filter zweimal benötigt (einmal pro UNION-Zweig)
    sql = f"""
        WITH q AS (
            SELECT websearch_to_tsquery('german', %s) AS query
        )
        (
            SELECT
                c.id AS id,
                'chunk' AS source,
                ts_rank(
                    setweight(to_tsvector('german', coalesce(ai.title_curated, '')), 'A') ||
                    setweight(c.tsv, 'B'),
                    q.query
                ) AS score,
                c.chunk_text AS text,
                c.top_key,
                c.start_page,
                c.end_page,
                c.document_id,
                d.meeting_date,
                d.gremium_name,
                d.gremium_key,
                d.source_filename AS filename
            FROM rag.chunks c
            JOIN raw.documents d ON d.id = c.document_id
            LEFT JOIN raw.document_agenda_items ai
              ON ai.document_id = c.document_id
             AND ai.top_key_curated = c.top_key
            CROSS JOIN q
            WHERE ((c.tsv @@ q.query)
               OR (to_tsvector('german', coalesce(ai.title_curated, '')) @@ q.query))
              {doc_filter_sql}
            ORDER BY score DESC
            LIMIT %s
        )
        UNION ALL
        (
            SELECT
                s.id AS id,
                'segment' AS source,
                ts_rank(
                    setweight(to_tsvector('german', coalesce(ai.title_curated, '')), 'A') ||
                    setweight(s.text_tsv, 'B'),
                    q.query
                ) AS score,
                s.text_full AS text,
                s.top_key,
                s.start_page,
                s.end_page,
                s.document_id,
                d.meeting_date,
                d.gremium_name,
                d.gremium_key,
                d.source_filename AS filename
            FROM raw.segments s
            JOIN raw.documents d ON d.id = s.document_id
            LEFT JOIN raw.document_agenda_items ai
              ON ai.document_id = s.document_id
             AND ai.top_key_curated = s.top_key
            CROSS JOIN q
            WHERE length(trim(coalesce(s.text_full, ''))) >= 20
              AND ((s.text_tsv @@ q.query)
                OR (to_tsvector('german', coalesce(ai.title_curated, '')) @@ q.query))
              {doc_filter_sql}
            ORDER BY score DESC
            LIMIT %s
        )
        ORDER BY score DESC
        LIMIT %s
    """
    params = (
        [query]
        + doc_filter_params + [n]
        + doc_filter_params + [n]
        + [n]
    )
    cur.execute(sql, tuple(params))
    rows = cur.fetchall()

    out = []
    for row in rows:
        out.append({
            "id": row["id"],
            "source": row["source"],
            "score": float(row["score"]),
            "text": row["text"] or "",
            "top_key": row["top_key"],
            "start_page": row["start_page"],
            "end_page": row["end_page"],
            "document_id": row["document_id"],
            "meeting_date": str(row["meeting_date"]) if row["meeting_date"] else None,
            "gremium_name": row["gremium_name"],
            "gremium_key": row["gremium_key"],
            "filename": row["filename"],
        })
    return out


# ─────────────────────────────────────────────
# Trigram Retrieval – mit Synonym-Erweiterung
# ─────────────────────────────────────────────

def detect_proper_nouns(query: str) -> list[str]:
    words = re.findall(r"[A-ZÄÖÜa-zäöüß]{5,}", query)
    proper = []
    for word in words:
        w_lower = word.lower()
        if any(w_lower.endswith(sfx) for sfx in PROPER_NOUN_SUFFIXES):
            proper.append(word)
            continue
        if len(word) >= 8 and any(c.isupper() for c in word[1:]):
            proper.append(word)
    return list(dict.fromkeys(proper))


def trigram_search(
    cur,
    query: str,
    n: int,
    extra_terms: list[str] | None = None,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[dict]:
    proper_nouns = detect_proper_nouns(query)

    candidates = list(proper_nouns)
    if extra_terms:
        for t in extra_terms:
            if len(t) >= 5 and t not in candidates:
                candidates.append(t)

    if not candidates:
        return []

    doc_filter_sql, doc_filter_params = build_document_filters(
        alias="d",
        gremium_key=gremium_key,
        year_from=year_from,
        year_to=year_to,
    )

    all_rows = {}

    for noun in candidates[:5]:
        # Chunks – nutzt Trigram-Index (chunks_text_trgm_idx)
        sql = f"""
            SELECT
                c.id                                        AS id,
                'chunk'                                     AS source,
                similarity(c.chunk_text, %s)                AS score,
                c.chunk_text                                AS text,
                c.top_key,
                c.start_page,
                c.end_page,
                c.document_id,
                d.meeting_date,
                d.gremium_name,
                d.gremium_key,
                d.source_filename                           AS filename
            FROM rag.chunks c
            JOIN raw.documents d ON d.id = c.document_id
            WHERE (c.chunk_text %% %s
               OR c.chunk_text ILIKE %s)
              {doc_filter_sql}
            ORDER BY score DESC
            LIMIT %s
        """
        params = [noun, noun, f"%{noun}%"] + doc_filter_params + [n]
        cur.execute(sql, tuple(params))

        for row in cur.fetchall():
            key = ("chunk", row["id"])
            if key not in all_rows or float(row["score"]) > float(all_rows[key]["score"]):
                all_rows[key] = row

        # Segmente – ILIKE auf text_full (kein Trigram-Index, aber Treffer-Set überschaubar)
        seg_sql = f"""
            SELECT
                s.id                                        AS id,
                'segment'                                   AS source,
                similarity(s.text_full, %s)                 AS score,
                s.text_full                                 AS text,
                s.top_key,
                s.start_page,
                s.end_page,
                s.document_id,
                d.meeting_date,
                d.gremium_name,
                d.gremium_key,
                d.source_filename                           AS filename
            FROM raw.segments s
            JOIN raw.documents d ON d.id = s.document_id
            WHERE s.text_full ILIKE %s
              AND length(trim(coalesce(s.text_full, ''))) >= 20
              {doc_filter_sql}
            ORDER BY score DESC
            LIMIT %s
        """
        seg_params = [noun, f"%{noun}%"] + doc_filter_params + [n]
        cur.execute(seg_sql, tuple(seg_params))

        for row in cur.fetchall():
            key = ("segment", row["id"])
            if key not in all_rows or float(row["score"]) > float(all_rows[key]["score"]):
                all_rows[key] = row

    out = []
    for row in sorted(all_rows.values(), key=lambda r: float(r["score"]), reverse=True)[:n]:
        out.append({
            "id": row["id"],
            "source": row["source"],
            "score": float(row["score"]),
            "text": row["text"] or "",
            "top_key": row["top_key"],
            "start_page": row["start_page"],
            "end_page": row["end_page"],
            "document_id": row["document_id"],
            "meeting_date": str(row["meeting_date"]) if row["meeting_date"] else None,
            "gremium_name": row["gremium_name"],
            "gremium_key": row["gremium_key"],
            "filename": row["filename"],
        })
    return out


# ─────────────────────────────────────────────
# Kandidatensteuerung
# ─────────────────────────────────────────────

def candidates_for_query(query: str, search_mode: str = "relevant") -> int:
    profile = get_mode_profile(search_mode)
    mult = profile["cand_multiplier"]

    if search_mode == "breadth":
        return int(CANDIDATES_BREADTH * mult)
    if re.search(RECENCY_SIGNAL_WORDS, query, re.IGNORECASE):
        return int(CANDIDATES_HIGH * mult)
    if detect_proper_nouns(query):
        return int(CANDIDATES_HIGH * mult)
    return int(CANDIDATES_BASE * mult)


# ─────────────────────────────────────────────
# Backfill
# ─────────────────────────────────────────────

def recent_topic_backfill(
    cur,
    query: str,
    extra_terms: list[str] | None = None,
    n_docs: int = BACKFILL_N_DOCS,
    n: int = BACKFILL_N_ROWS,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[dict]:
    nouns = detect_proper_nouns(query)
    if not nouns and not extra_terms:
        return []

    terms = []
    for t in nouns[:BACKFILL_MAX_TERMS]:
        terms.append(t)
        terms.append(t.replace("ß", "ss"))
    if extra_terms:
        for t in extra_terms[:BACKFILL_MAX_TERMS]:
            if t and t not in terms:
                terms.append(t)

    if not terms:
        return []

    doc_filter_sql, doc_filter_params = build_document_filters(
        alias="d",
        gremium_key=gremium_key,
        year_from=year_from,
        year_to=year_to,
    )

    cur.execute(f"""
        SELECT d.id
        FROM raw.documents d
        WHERE 1=1
          {doc_filter_sql}
        ORDER BY d.meeting_date DESC
        LIMIT %s
    """, tuple(doc_filter_params + [n_docs]))
    doc_ids = [r["id"] for r in cur.fetchall()]
    if not doc_ids:
        return []

    ilikes_chunk = " OR ".join(["c.chunk_text ILIKE %s"] * len(terms))
    ilikes_seg = " OR ".join(["s.text_full ILIKE %s"] * len(terms))
    like_params = [f"%{t}%" for t in terms]

    cur.execute(f"""
        SELECT
            c.id AS id,
            'chunk' AS source,
            0.2 AS score,
            c.chunk_text AS text,
            c.top_key,
            c.start_page,
            c.end_page,
            c.document_id,
            d.meeting_date,
            d.gremium_name,
            d.gremium_key,
            d.source_filename AS filename
        FROM rag.chunks c
        JOIN raw.documents d ON d.id = c.document_id
        WHERE c.document_id = ANY(%s)
          AND ({ilikes_chunk})
        ORDER BY d.meeting_date DESC, c.start_page ASC
        LIMIT %s
    """, (doc_ids, *like_params, n))
    chunk_rows = cur.fetchall()

    cur.execute(f"""
        SELECT
            s.id AS id,
            'segment' AS source,
            0.2 AS score,
            s.text_full AS text,
            s.top_key,
            s.start_page,
            s.end_page,
            s.document_id,
            d.meeting_date,
            d.gremium_name,
            d.gremium_key,
            d.source_filename AS filename
        FROM raw.segments s
        JOIN raw.documents d ON d.id = s.document_id
        WHERE s.document_id = ANY(%s)
          AND length(trim(coalesce(s.text_full, ''))) >= 20
          AND ({ilikes_seg})
        ORDER BY d.meeting_date DESC, s.start_page ASC
        LIMIT %s
    """, (doc_ids, *like_params, n))
    seg_rows = cur.fetchall()

    rows = list(chunk_rows) + list(seg_rows)
    out = []
    for r in rows:
        out.append({
            "id": r["id"],
            "source": r["source"],
            "score": float(r["score"]),
            "text": r["text"] or "",
            "top_key": r["top_key"],
            "start_page": r["start_page"],
            "end_page": r["end_page"],
            "document_id": r["document_id"],
            "meeting_date": str(r["meeting_date"]) if r["meeting_date"] else None,
            "gremium_name": r["gremium_name"],
            "gremium_key": r["gremium_key"],
            "filename": r["filename"],
            "is_backfill": True,
        })
    return out


# ─────────────────────────────────────────────
# RRF Merge
# ─────────────────────────────────────────────

def rrf_merge_multi(
    dense_lists: list[list[dict]],
    sparse_results: list[dict],
    trigram_results: list[dict] | None = None,
    k_dense: int = RRF_K_DENSE,
    k_dense_paraphrase: int = RRF_K_PARAPHRASE,
    k_sparse: int = RRF_K_SPARSE,
    k_trigram: int = RRF_K_TRIGRAM,
) -> list[dict]:
    scores = {}
    meta = {}

    for i, results in enumerate(dense_lists):
        k = k_dense if i == 0 else k_dense_paraphrase
        for rank, item in enumerate(results, start=1):
            key = (item["source"], item["id"])
            scores[key] = scores.get(key, 0.0) + 1.0 / (k + rank)
            meta[key] = item

    for rank, item in enumerate(sparse_results, start=1):
        key = (item["source"], item["id"])
        scores[key] = scores.get(key, 0.0) + 1.0 / (k_sparse + rank)
        meta[key] = item

    if trigram_results:
        for rank, item in enumerate(trigram_results, start=1):
            key = (item["source"], item["id"])
            scores[key] = scores.get(key, 0.0) + 1.0 / (k_trigram + rank)
            meta[key] = item

    merged = []
    for key in sorted(scores, key=lambda k: scores[k], reverse=True):
        it = dict(meta[key])
        it["rrf_score"] = scores[key]
        merged.append(it)
    return merged


def rrf_add_list(merged: list[dict], extra: list[dict], k: int = RRF_K_BACKFILL) -> list[dict]:
    scores = {(m["source"], m["id"]): m.get("rrf_score", 0.0) for m in merged}
    meta = {(m["source"], m["id"]): m for m in merged}

    for rank, item in enumerate(extra, start=1):
        key = (item["source"], item["id"])
        scores[key] = scores.get(key, 0.0) + 1.0 / (k + rank)
        if key not in meta:
            meta[key] = item
        else:
            meta[key]["is_backfill"] = meta[key].get("is_backfill") or item.get("is_backfill")

    out = []
    for key in sorted(scores, key=lambda kk: scores[kk], reverse=True):
        it = dict(meta[key])
        it["rrf_score"] = scores[key]
        out.append(it)
    return out


# ─────────────────────────────────────────────
# Strukturabfrage (Attendance)
# ─────────────────────────────────────────────

def detect_faction(query: str) -> str | None:
    q = query.lower()
    for key, aliases in FACTION_ALIASES.items():
        for a in aliases:
            if a in q:
                return key
    return None


def detect_person(query: str) -> str | None:
    # Fraktions-Token nicht als Personennamen werten (z. B. "… von Volt")
    faction_tokens = {a for aliases in FACTION_ALIASES.values() for a in aliases}

    # "Herr/Frau [Titel] Nachname" -> Nachname (letztes großgeschriebenes Wort, Titel raus).
    # structured_search matcht auf last_name_curated, daher zählt der Nachname, nicht der Vorname.
    m = re.search(
        r"(?:Herr|Frau)\s+((?:[A-ZÄÖÜ][a-zäöüß\-]+\.?\s+)*[A-ZÄÖÜ][a-zäöüß\-]+)",
        query,
    )
    if m:
        parts = [p for p in m.group(1).split()
                 if not re.fullmatch(r"(Dr|Prof|Dipl|Med)\.?", p)]
        if parts:
            return parts[-1]

    stopwords = {
        "wer", "was", "wie", "wann", "wo", "welche", "welcher", "welches",
        "wurde", "werden", "worden", "haben", "hatte", "hat", "sein", "war",
        "ist", "sind", "waren", "wird", "für", "der", "die", "das", "und",
        "oder", "aber", "auch", "noch", "nicht", "nur", "alle", "schon",
        "sitzung", "protokoll", "bezirksvertretung", "fraktion", "mitglied",
        "jahr", "jahre", "zuletzt", "seit", "nach", "über", "unter", "beim",
        "tätig", "dabei", "grünen", "linke", "partei", "gruppe",
        "gesagt", "gefragt", "erklärt", "meinte", "sagte", "fragte",
    }

    words = query.split()
    candidates = []
    for i, w in enumerate(words):
        clean = re.sub(r"[^a-zA-ZäöüÄÖÜß\-]", "", w)
        if (i > 0 and len(clean) >= 4 and clean[0].isupper()
                and clean.lower() not in stopwords
                and clean.lower() not in faction_tokens):
            candidates.append(clean)

    # In "Vorname Nachname" steht der Nachname zuletzt
    return candidates[-1] if candidates else None


def structured_search(
    cur,
    query: str,
    n: int = 20,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
) -> list[dict]:
    faction_key = detect_faction(query)
    person_name = detect_person(query)

    if not faction_key and not person_name:
        return []

    year_match = re.search(r"\b(20\d{2}|199\d)\b", query)
    year = year_match.group(1) if year_match else None

    conditions = []
    params = []

    if faction_key:
        aliases = FACTION_ALIASES[faction_key]
        faction_conds = " OR ".join(["LOWER(a.faction_curated) LIKE %s"] * len(aliases))
        conditions.append(f"({faction_conds})")
        params.extend([f"%{a}%" for a in aliases])

    if person_name:
        conditions.append("LOWER(a.last_name_curated) LIKE %s")
        params.append(f"%{person_name.lower()}%")

    if year:
        conditions.append("EXTRACT(YEAR FROM d.meeting_date)::text = %s")
        params.append(year)

    if gremium_key:
        conditions.append("d.gremium_key = %s")
        params.append(gremium_key)

    if year_from is not None:
        conditions.append("EXTRACT(YEAR FROM d.meeting_date) >= %s")
        params.append(year_from)

    if year_to is not None:
        conditions.append("EXTRACT(YEAR FROM d.meeting_date) <= %s")
        params.append(year_to)

    where = " AND ".join(conditions)

    cur.execute(f"""
        SELECT
            a.id,
            a.document_id,
            a.last_name_curated AS last_name,
            a.salutation,
            a.role_curated      AS role,
            a.faction_curated   AS faction,
            d.meeting_date,
            d.gremium_name,
            d.gremium_key,
            d.source_filename   AS filename,
            dc.curated_sitzungstyp      AS sitzungstyp,
            dc.curated_niederschrift_nr AS niederschrift_nr
        FROM raw.document_attendance_rows a
        JOIN raw.documents d           ON d.id = a.document_id
        LEFT JOIN raw.document_core dc ON dc.document_id = a.document_id
        WHERE {where}
        ORDER BY d.meeting_date DESC
        LIMIT %s
    """, (*params, n))

    rows = cur.fetchall()

    out = []
    for row in rows:
        name = " ".join(filter(None, [row["salutation"], row["last_name"]]))
        out.append({
            "id": row["id"],
            "source": "attendance",
            "score": 1.0,
            "text": f"{name} ({row['faction'] or 'keine Fraktion'}) – Sitzung vom {row['meeting_date']}",
            "top_key": None,
            "start_page": None,
            "end_page": None,
            "document_id": row["document_id"],
            "meeting_date": str(row["meeting_date"]) if row["meeting_date"] else None,
            "gremium_name": row["gremium_name"],
            "gremium_key": row["gremium_key"],
            "filename": row["filename"],
            "sitzungstyp": row["sitzungstyp"],
            "niederschrift_nr": row["niederschrift_nr"],
            "top_title": None,
            "person_name": name,
            "faction": row["faction"],
            "role": row["role"],
        })
    return out


# ─────────────────────────────────────────────
# Query-Signale + Boosts
# ─────────────────────────────────────────────

def parse_query_signals(query: str) -> dict:
    signals = {
        "top_key": None,
        "date": None,
        "drucksache": None,
        "multi_doc": False,
        "raw_query": query,
    }

    m = re.search(r"\bTOP\s*(\d+[a-zA-Z]?)", query, re.IGNORECASE)
    if m:
        signals["top_key"] = m.group(1)

    m = re.search(r"(\d{2})\.(\d{2})\.(\d{4})", query)
    if m:
        signals["date"] = f"{m.group(3)}-{m.group(2)}-{m.group(1)}"

    m = re.search(r"(?:Drucksache|DS)\s*(\d{4}/\d+)", query, re.IGNORECASE)
    if m:
        signals["drucksache"] = m.group(1)

    if re.search(r"entwickelt|verändert|über die Jahre|im Laufe|seit \d{4}", query, re.IGNORECASE):
        signals["multi_doc"] = True

    return signals


def _recency_01(meeting_date_str: str | None, half_life_days: int = RECENCY_HALF_LIFE_DAYS) -> float:
    if not meeting_date_str:
        return 0.0
    try:
        d = datetime.strptime(meeting_date_str, "%Y-%m-%d").date()
    except Exception:
        return 0.0

    age_days = (date.today() - d).days
    if age_days <= 0:
        return 1.0
    if age_days >= half_life_days:
        return 0.0
    return 1.0 - (age_days / half_life_days)


def apply_boosts(merged: list[dict], signals: dict, search_mode: str = "relevant") -> list[dict]:
    profile = get_mode_profile(search_mode)
    recency_boost_max = profile["recency_boost"]
    wants_current = bool(re.search(RECENCY_SIGNAL_WORDS, signals.get("raw_query", ""), re.IGNORECASE))
    recency_multiplier = profile["recency_mult_if_current"] if wants_current else 1.0

    for item in merged:
        boost = 0.0

        if signals["top_key"] and item.get("top_key") == signals["top_key"]:
            boost += 0.05

        if signals["date"] and item.get("meeting_date") == signals["date"]:
            boost += 0.05

        if item.get("source") == "segment":
            boost += 0.08

        if (not signals.get("multi_doc", False)) and recency_boost_max > 0:
            boost += recency_boost_max * recency_multiplier * _recency_01(item.get("meeting_date"))

        item["final_score"] = item.get("rrf_score", 0.0) + boost

    merged.sort(key=lambda x: x.get("final_score", 0.0), reverse=True)
    return merged


# ─────────────────────────────────────────────
# Dokument-Cap (Diversität)
# ─────────────────────────────────────────────

def apply_doc_cap(results: list[dict], cap: int) -> list[dict]:
    """
    Diversitätsfilter mit zwei Schritten:

    1. Pro (document_id, top_key) wird Segment vor Chunk bevorzugt, sofern
       die Score-Differenz unter SEGMENT_PREFERENCE_THRESHOLD liegt (Soft).
       Bei deutlich besserem Chunk-Score gewinnt der Chunk.
       Einträge ohne top_key gehen unangetastet durch.
    2. Pro document_id werden maximal `cap` *verschiedene* top_keys behalten.
       Mehrere Einträge aus demselben (doc, top) sind nach Schritt 1 ohnehin
       weg – Schritt 2 begrenzt also die TOP-Diversität pro Dokument.

    Attendance-Einträge sind von beiden Schritten ausgenommen.
    Annahme: `results` ist bereits nach final_score absteigend sortiert.
    """
    if cap <= 0:
        return results

    SEGMENT_PREFERENCE_THRESHOLD = 0.10  # 10 %

    attendance = [r for r in results if r.get("source") == "attendance"]
    others = [r for r in results if r.get("source") != "attendance"]

    # Schritt 1: pro (document_id, top_key) Segment vs. Chunk auflösen.
    # Einträge ohne top_key (oder ohne document_id) gehen direkt durch.
    grouped: dict = {}
    passthrough: list = []

    for item in others:
        did = item.get("document_id")
        top_key = item.get("top_key")
        if did is None or top_key is None:
            passthrough.append(item)
            continue
        grouped.setdefault((did, top_key), []).append(item)

    deduped: list = []
    for (did, top_key), items in grouped.items():
        if len(items) == 1:
            deduped.append(items[0])
            continue

        seg = max(
            (i for i in items if i.get("source") == "segment"),
            key=lambda x: x.get("final_score", 0.0),
            default=None,
        )
        chunk = max(
            (i for i in items if i.get("source") == "chunk"),
            key=lambda x: x.get("final_score", 0.0),
            default=None,
        )

        if seg and chunk:
            seg_score = seg.get("final_score", 0.0)
            chunk_score = chunk.get("final_score", 0.0)
            if chunk_score <= 0:
                deduped.append(seg)
            elif (chunk_score - seg_score) / chunk_score < SEGMENT_PREFERENCE_THRESHOLD:
                deduped.append(seg)
            else:
                deduped.append(chunk)
        elif seg:
            deduped.append(seg)
        elif chunk:
            deduped.append(chunk)

    combined = deduped + passthrough
    combined.sort(key=lambda x: x.get("final_score", 0.0), reverse=True)

    # Schritt 2: pro document_id max. `cap` *verschiedene* top_keys behalten.
    # Einträge ohne top_key oder ohne document_id zählen nicht gegen den Cap.
    seen_tops_per_doc: dict = {}
    capped: list = []
    for item in combined:
        did = item.get("document_id")
        top_key = item.get("top_key")

        if did is None or top_key is None:
            capped.append(item)
            continue

        seen = seen_tops_per_doc.setdefault(did, set())
        if top_key in seen:
            # sollte nach Schritt 1 nicht mehr vorkommen, aber sicher ist sicher
            capped.append(item)
            continue

        if len(seen) >= cap:
            continue

        seen.add(top_key)
        capped.append(item)

    return attendance + capped


# ─────────────────────────────────────────────
# Ambiguitätsprüfung
# ─────────────────────────────────────────────

def detect_attendance_ambiguity(results: list[dict], query: str) -> dict | None:
    attendance = [r for r in results if r.get("source") == "attendance"]
    if len(attendance) < 2:
        return None

    person = detect_person(query)
    faction = detect_faction(query)

    matching = attendance

    if person:
        matching = [
            r for r in matching
            if person.lower() in (r.get("person_name") or "").lower()
        ]

    if faction:
        aliases = [a.lower() for a in FACTION_ALIASES.get(faction, [])]
        matching = [
            r for r in matching
            if any(a in (r.get("faction") or "").lower() for a in aliases)
        ]

    if len(matching) < 2:
        return None

    gremien = {}
    for r in matching:
        gk = r.get("gremium_key")
        gn = r.get("gremium_name") or "unbekannt"
        if gk:
            gremien[gk] = gn

    if len(gremien) <= 1:
        return None

    options = [name for _, name in sorted(gremien.items(), key=lambda x: x[1])]

    return {
        "person": person or "die angefragte Person",
        "options": options,
    }


def focus_results_to_attendance_gremium(results: list[dict], signals: dict) -> list[dict]:
    if not results or signals.get("multi_doc", False):
        return results

    top = results[0]
    if top.get("source") != "attendance":
        return results

    target_gremium_key = top.get("gremium_key")
    if not target_gremium_key:
        return results

    focused = [r for r in results if r.get("gremium_key") == target_gremium_key]
    return focused or results


# ─────────────────────────────────────────────
# Kontext-Anreicherung
# ─────────────────────────────────────────────

def enrich_with_metadata(cur, results: list[dict]) -> list[dict]:
    doc_ids = list({r["document_id"] for r in results if r.get("document_id")})
    if not doc_ids:
        return results

    cur.execute("""
        SELECT document_id, curated_sitzungstyp, curated_niederschrift_nr, curated_periodenbezug
        FROM raw.document_core
        WHERE document_id = ANY(%s)
    """, (doc_ids,))
    core_map = {row["document_id"]: row for row in cur.fetchall()}

    cur.execute("""
        SELECT document_id, top_key_curated, title_curated, drucksache_curated, top_num, top_suffix, top_sub
        FROM raw.document_agenda_items
        WHERE document_id = ANY(%s)
          AND top_key_curated IS NOT NULL
        ORDER BY document_id, top_num, top_suffix, top_sub
    """, (doc_ids,))
    agenda_map = {}
    agenda_full = {}
    drucksache_map = {}
    for row in cur.fetchall():
        did = row["document_id"]
        k = row["top_key_curated"]
        agenda_map[(did, k)] = row["title_curated"]
        agenda_full.setdefault(did, []).append((k, row["title_curated"], row.get("drucksache_curated")))
        if row.get("drucksache_curated"):
            drucksache_map[(did, k)] = row["drucksache_curated"]

    cur.execute("""
        SELECT
            document_id,
            salutation,
            last_name_curated AS last_name,
            faction_curated   AS faction,
            role_curated      AS role,
            row_index
        FROM raw.document_attendance_rows
        WHERE document_id = ANY(%s)
        ORDER BY document_id, row_index
    """, (doc_ids,))
    attendance_map = {}
    for row in cur.fetchall():
        did = row["document_id"]
        name = " ".join(filter(None, [row["salutation"], row["last_name"]]))
        attendance_map.setdefault(did, []).append({
            "name": name,
            "faction": row["faction"] or "",
            "role": row["role"] or "",
        })

    for item in results:
        did = item["document_id"]
        top_key = item.get("top_key")

        core = core_map.get(did, {})
        item["sitzungstyp"] = core.get("curated_sitzungstyp")
        item["niederschrift_nr"] = core.get("curated_niederschrift_nr")
        item["wahlperiode"] = core.get("curated_periodenbezug")

        item["top_title"] = agenda_map.get((did, top_key)) if top_key else None
        item["drucksache"] = drucksache_map.get((did, top_key)) if top_key else None
        item["agenda_full"] = agenda_full.get(did, [])
        item["attendance_full"] = attendance_map.get(did, [])

    return results


def merge_structured_results(
    merged: list[dict],
    structured_results: list[dict],
    query: str,
) -> list[dict]:
    if not structured_results:
        return merged

    attendance_mode = is_attendance_query(query)
    person_statement_mode = is_person_statement_query(query)

    if attendance_mode and not person_statement_mode:
        for item in structured_results:
            item["rrf_score"] = 1.0
            item["final_score"] = 1.0
        return structured_results + merged

    structured_docs = {r["document_id"] for r in structured_results if r.get("document_id")}

    for item in merged:
        if item.get("document_id") in structured_docs:
            item["rrf_score"] = item.get("rrf_score", 0.0) + STRUCTURED_DOC_BOOST

    for item in structured_results:
        item["rrf_score"] = STRUCTURED_RESULT_BASE
        item["final_score"] = STRUCTURED_RESULT_BASE

    return merged + structured_results


# ─────────────────────────────────────────────
# Hauptfunktion
# ─────────────────────────────────────────────

def retrieve(
    query: str,
    top_k: int = 10,
    gremium_key: str | None = None,
    year_from: int | None = None,
    year_to: int | None = None,
    search_mode: str = "relevant",
) -> list[dict]:
    conn = psycopg2.connect(DSN)
    conn.autocommit = True
    cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)

    try:
        signals = parse_query_signals(query)
        profile = get_mode_profile(search_mode)
        n_cand = candidates_for_query(query, search_mode)

        try:
            synonyms = get_synonyms(query)
        except Exception:
            synonyms = {}

        extra_terms = flatten_variants(synonyms)
        paraphrases = generate_query_paraphrases(query, synonyms)

        dense_queries = [query] + paraphrases
        dense_lists = multi_dense_search(
            cur,
            dense_queries,
            n_cand,
            gremium_key=gremium_key,
            year_from=year_from,
            year_to=year_to,
        )

        expanded_fts_query = expand_query_for_fts(query, synonyms)
        sparse_results = sparse_search(
            cur,
            expanded_fts_query,
            n_cand,
            gremium_key=gremium_key,
            year_from=year_from,
            year_to=year_to,
        )

        trigram_results = trigram_search(
            cur,
            query,
            n_cand,
            extra_terms=extra_terms,
            gremium_key=gremium_key,
            year_from=year_from,
            year_to=year_to,
        )

        structured_results = []
        if wants_person_metadata(query) or detect_faction(query):
            structured_results = structured_search(
                cur,
                query,
                n=20,
                gremium_key=gremium_key,
                year_from=year_from,
                year_to=year_to,
            )

        merged = rrf_merge_multi(dense_lists, sparse_results, trigram_results)

        wants_current = bool(re.search(RECENCY_SIGNAL_WORDS, query, re.IGNORECASE))
        has_topic = bool(detect_proper_nouns(query)) or bool(extra_terms)
        if (not signals.get("multi_doc", False)) and (wants_current or has_topic):
            backfill_results = recent_topic_backfill(
                cur,
                query,
                extra_terms=extra_terms,
                gremium_key=gremium_key,
                year_from=year_from,
                year_to=year_to,
            )
            if backfill_results:
                merged = rrf_add_list(merged, backfill_results, k=RRF_K_BACKFILL)

        merged = merge_structured_results(merged, structured_results, query)
        merged = apply_boosts(merged, signals, search_mode=search_mode)

        ambiguity = detect_attendance_ambiguity(merged, query)
        if ambiguity and is_attendance_query(query) and not is_person_statement_query(query):
            return [{
                "clarify_needed": True,
                "clarify_hint": (
                    f"Zu „{ambiguity['person']}“ gibt es Treffer in mehreren Gremien: "
                    + "; ".join(ambiguity["options"])
                    + ". Bitte präzisieren Sie Gremium, Vorname oder Datum."
                )
            }]

        if is_attendance_query(query) and not is_person_statement_query(query):
            merged = focus_results_to_attendance_gremium(merged, signals)

        merged = apply_doc_cap(merged, cap=profile["doc_cap"])

        top_results = merged[:top_k]

        chunk_results = [r for r in top_results if r.get("source") != "attendance"]
        attendance_results = [r for r in top_results if r.get("source") == "attendance"]

        chunk_results = enrich_with_metadata(cur, chunk_results)
        return attendance_results + chunk_results

    finally:
        cur.close()
        conn.close()


# ─────────────────────────────────────────────
# CLI
# ─────────────────────────────────────────────

def format_result(item: dict, rank: int) -> str:
    if item.get("source") == "attendance":
        date_ = item.get("meeting_date") or "–"
        score = item.get("final_score", item.get("rrf_score", 0.0))
        return "\n".join([
            f"┌─ #{rank:02d}  [ATTENDANCE]  Score: {score:.4f}",
            f"│  Datum:    {date_}",
            f"│  Person:   {item.get('person_name','–')}  ({item.get('faction','–')})",
            f"│  Rolle:    {item.get('role','–')}",
            f"│  Gremium:  {item.get('gremium_name','–')}",
            f"└{'─' * 70}",
        ])

    source = item.get("source")
    source_label = "SEGMENT" if source == "segment" else "CHUNK"
    backfill_flag = " [BACKFILL]" if item.get("is_backfill") else ""

    date_ = item.get("meeting_date") or "–"
    top = item.get("top_key") or "–"
    title = item.get("top_title") or ""
    pages = f"S. {item.get('start_page')}–{item.get('end_page')}" if item.get("start_page") else "S. –"
    score = item.get("final_score", 0.0)
    text = (item.get("text") or "")[:300].replace("\n", " ")
    top_info = f"TOP {top}" + (f" – {title}" if title else "")

    return "\n".join([
        f"┌─ #{rank:02d}  [{source_label}{backfill_flag}]  Score: {score:.4f}",
        f"│  Datum:    {date_}   {pages}",
        f"│  TOP:      {top_info}",
        f"│  Gremium:  {item.get('gremium_name') or '–'}",
        f"│  Datei:    {item.get('filename') or '–'}",
        f"│  Text:     {text}{'…' if len(item.get('text','')) > 300 else ''}",
        f"└{'─' * 70}",
    ])


def main():
    p = argparse.ArgumentParser(description="KommRAG Hybrid Retrieval – Phase 5")
    p.add_argument("--query", "-q", required=True, help="Suchanfrage")
    p.add_argument("--top-k", "-k", type=int, default=10, help="Anzahl Ergebnisse (default: 10)")
    p.add_argument("--gremium-key", type=str, default=None, help="Filter auf Gremium-Key")
    p.add_argument("--year-from", type=int, default=None, help="Jahr ab")
    p.add_argument("--year-to", type=int, default=None, help="Jahr bis")
    p.add_argument("--mode", type=str, default="relevant", choices=["relevant","recent","breadth"], help="Suchmodus")
    p.add_argument("--json", action="store_true", help="Zusätzlich JSON ausgeben")
    args = p.parse_args()

    print(f"\n🔍 Query: {args.query}")
    print(f"   Top-K: {args.top_k}")
    print(f"   Modus: {args.mode}")
    print(f"   Gremium: {args.gremium_key or 'alle'}")
    print(f"   Jahr von: {args.year_from or '–'}")
    print(f"   Jahr bis: {args.year_to or '–'}\n")

    t0 = time.time()
    results = retrieve(
        args.query,
        top_k=args.top_k,
        gremium_key=args.gremium_key,
        year_from=args.year_from,
        year_to=args.year_to,
        search_mode=args.mode,
    )
    elapsed = time.time() - t0

    if results and results[0].get("clarify_needed"):
        print("═" * 72)
        print("  Rückfrage erforderlich")
        print("═" * 72 + "\n")
        print(results[0]["clarify_hint"])
        return

    print("═" * 72)
    print(f"  {len(results)} Ergebnisse  ({elapsed:.2f}s)")
    print("═" * 72 + "\n")

    for i, item in enumerate(results, start=1):
        print(format_result(item, i))
        print()

    if args.json:
        print("\n─── JSON ───────────────────────────────────────────────────────────")
        print(json.dumps(results, ensure_ascii=False, indent=2, default=str))

    out_path = os.path.join(SCRIPT_DIR, "last_retrieve.json")
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2, default=str)
    print(f"\n💾 Ergebnisse gespeichert: {out_path}")


if __name__ == "__main__":
    main()
