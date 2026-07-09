"""
KommRAG – System 2 API
FastAPI-Endpunkt für System 3 (Web-Frontend)

Endpunkte:
  POST /chat          – klassische JSON-Antwort
  POST /chat/stream   – NDJSON-Stream
  DELETE /session     – Gesprächsverlauf einer Session löschen
  GET  /health        – Systemstatus
  GET  /gremien       – Liste verfügbarer Gremien
"""

import os
import time
import json
import secrets
import logging
from datetime import datetime
from typing import Optional

import psycopg2
import psycopg2.extras
from fastapi import FastAPI, HTTPException, Header
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field
from dotenv import load_dotenv

import sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from answer import answer_once, answer_stream

# ─────────────────────────────────────────────
# Konfiguration
# ─────────────────────────────────────────────

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(SCRIPT_DIR, ".env"), override=True)

DSN     = os.environ["KOMMRAG_DSN"]
API_KEY = os.environ["KOMMRAG_API_KEY"]

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("kommrag-api")

# ─────────────────────────────────────────────
# Session-Speicher
# ─────────────────────────────────────────────

SESSIONS: dict[str, list[dict]] = {}
SESSION_MAX_TURNS = 6

# ─────────────────────────────────────────────
# App
# ─────────────────────────────────────────────

app = FastAPI(
    title="KommRAG API",
    description="RAG-System für kommunale Ratsprotokolle – Stadt Essen",
    version="1.1.0",
    docs_url=None,
    redoc_url=None,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "https://fragessen.stadtstimme.de",
        "https://stadtstimme.de",
        "http://localhost",
        "http://127.0.0.1",
    ],
    allow_methods=["GET", "POST", "DELETE"],
    allow_headers=["Content-Type", "X-API-Key"],
)

# ─────────────────────────────────────────────
# Auth
# ─────────────────────────────────────────────

def verify_api_key(x_api_key: Optional[str] = Header(default=None)):
    if not x_api_key or not secrets.compare_digest(x_api_key, API_KEY):
        raise HTTPException(status_code=401, detail="Unauthorized")


# ─────────────────────────────────────────────
# Modelle
# ─────────────────────────────────────────────

class ChatRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=1000)
    session_id: str = Field(..., min_length=8, max_length=64)
    top_k: int = Field(default=10, ge=3, le=20)
    gremium_key: Optional[str] = Field(default=None)
    year_from: Optional[int] = Field(default=None, ge=2000, le=2035)
    year_to: Optional[int] = Field(default=None, ge=2000, le=2035)
    answer_length: str = Field(default="normal")
    quality: str = Field(default="small")
    search_mode: str = Field(default="relevant")

class SourceItem(BaseModel):
    rank: int
    type: str
    date: Optional[str]
    top_key: Optional[str]
    top_title: Optional[str]
    page_from: Optional[int]
    page_to: Optional[int]
    niederschrift_nr: Optional[int]
    document_id: Optional[int]
    filename: Optional[str]
    score: float

class ChatResponse(BaseModel):
    answer: Optional[str]
    sources: list[SourceItem]
    condensed_query: str
    clarify: Optional[str]
    elapsed_ms: int
    session_id: str

class SessionDeleteResponse(BaseModel):
    cleared: bool
    session_id: str

class HealthResponse(BaseModel):
    status: str
    timestamp: str
    sessions_active: int

class GremiumItem(BaseModel):
    key: str
    name: str
    doc_count: int
    last_date: Optional[str] = None


# ─────────────────────────────────────────────
# Helper
# ─────────────────────────────────────────────

def chunks_to_sources(chunks: list[dict]) -> list[SourceItem]:
    seen = set()
    sources = []
    for i, c in enumerate(chunks, start=1):
        key = (c.get("meeting_date"), c.get("top_key"), c.get("source") == "attendance")
        if key in seen:
            continue
        seen.add(key)
        sources.append(SourceItem(
            rank=i,
            type=c.get("source", "chunk"),
            date=c.get("meeting_date"),
            top_key=c.get("top_key"),
            top_title=c.get("top_title"),
            page_from=c.get("start_page"),
            page_to=c.get("end_page"),
            niederschrift_nr=c.get("niederschrift_nr"),
            document_id=c.get("document_id"),
            filename=c.get("filename"),
            score=round(float(c.get("final_score", 0) or 0), 4),
        ))
    return sources


def get_gremium_name(gremium_key: Optional[str]) -> Optional[str]:
    if not gremium_key:
        return None
    try:
        conn = psycopg2.connect(DSN)
        cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cur.execute(
            "SELECT gremium_name FROM raw.documents WHERE gremium_key = %s LIMIT 1",
            (gremium_key,),
        )
        row = cur.fetchone()
        cur.close()
        conn.close()
        if row:
            return row["gremium_name"]
    except Exception:
        pass
    return None


def update_session_history(session_id: str, query: str, answer: Optional[str]) -> None:
    if not answer:
        return

    history = SESSIONS.get(session_id, [])
    history.append({"role": "user", "content": query})
    history.append({"role": "assistant", "content": answer})

    if len(history) > SESSION_MAX_TURNS * 2:
        history = history[-(SESSION_MAX_TURNS * 2):]

    SESSIONS[session_id] = history


def normalize_answer_length(value: str) -> str:
    return value if value in ("short", "normal", "detailed") else "normal"


def normalize_search_mode(value: str) -> str:
    return value if value in ("relevant", "recent", "breadth") else "relevant"


def event_line(payload: dict) -> bytes:
    return (json.dumps(payload, ensure_ascii=False) + "\n").encode("utf-8")


# ─────────────────────────────────────────────
# Endpunkte
# ─────────────────────────────────────────────

@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest, x_api_key: Optional[str] = Header(default=None)):
    verify_api_key(x_api_key)

    history = SESSIONS.get(req.session_id, [])
    gremium_name = get_gremium_name(req.gremium_key)

    t0 = time.time()
    try:
        result = answer_once(
            query=req.query,
            history=history,
            top_k=req.top_k,
            gremium_key=req.gremium_key,
            gremium_name=gremium_name,
            year_from=req.year_from,
            year_to=req.year_to,
            answer_length=normalize_answer_length(req.answer_length),
            quality=req.quality,
            search_mode=normalize_search_mode(req.search_mode),
        )
    except Exception as e:
        log.error(f"answer_once() Fehler: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"RAG-Fehler: {str(e)}")

    elapsed_ms = int((time.time() - t0) * 1000)

    if result.get("answer"):
        update_session_history(req.session_id, req.query, result["answer"])

    log.info(
        f"sync session={req.session_id[:8]} "
        f"top_k={req.top_k} "
        f"elapsed={elapsed_ms}ms "
        f"chunks={len(result.get('chunks', []))} "
        f"query={req.query[:60]!r}"
    )

    return ChatResponse(
        answer=result.get("answer"),
        sources=chunks_to_sources(result.get("chunks", [])),
        condensed_query=result.get("condensed", req.query),
        clarify=result.get("clarify"),
        elapsed_ms=elapsed_ms,
        session_id=req.session_id,
    )


@app.post("/chat/stream")
async def chat_stream(req: ChatRequest, x_api_key: Optional[str] = Header(default=None)):
    verify_api_key(x_api_key)

    history = SESSIONS.get(req.session_id, [])
    gremium_name = get_gremium_name(req.gremium_key)

    def generator():
        final_answer = None
        final_sources = []
        final_condensed = req.query
        final_clarify = None
        final_elapsed_ms = None
        chunk_count = 0

        try:
            for event in answer_stream(
                query=req.query,
                history=history,
                top_k=req.top_k,
                gremium_key=req.gremium_key,
                gremium_name=gremium_name,
                year_from=req.year_from,
                year_to=req.year_to,
                answer_length=normalize_answer_length(req.answer_length),
                quality=req.quality,
                search_mode=normalize_search_mode(req.search_mode),
            ):
                etype = event.get("type")

                if etype == "done":
                    final_answer = event.get("answer")
                    final_sources = event.get("sources", [])
                    final_condensed = event.get("condensed_query", req.query)
                    final_clarify = event.get("clarify")
                    final_elapsed_ms = event.get("elapsed_ms", 0)
                    chunk_count = len(final_sources)

                elif etype == "clarify":
                    final_condensed = event.get("condensed_query", req.query)
                    final_clarify = event.get("message")
                    final_elapsed_ms = event.get("elapsed_ms", 0)

                yield event_line(event)

            if final_answer:
                update_session_history(req.session_id, req.query, final_answer)

            log.info(
                f"stream session={req.session_id[:8]} "
                f"top_k={req.top_k} "
                f"elapsed={final_elapsed_ms or 0}ms "
                f"chunks={chunk_count} "
                f"query={req.query[:60]!r}"
            )

        except Exception as e:
            log.error(f"chat_stream() Fehler: {e}", exc_info=True)
            yield event_line({
                "type": "error",
                "message": f"Stream-Fehler: {str(e)}",
            })

    return StreamingResponse(
        generator(),
        media_type="application/x-ndjson",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
        },
    )


@app.delete("/session", response_model=SessionDeleteResponse)
async def delete_session(
    session_id: str,
    x_api_key: Optional[str] = Header(default=None),
):
    verify_api_key(x_api_key)
    cleared = session_id in SESSIONS
    SESSIONS.pop(session_id, None)
    log.info(f"Session gelöscht: {session_id[:8]}")
    return SessionDeleteResponse(cleared=cleared, session_id=session_id)


@app.get("/health", response_model=HealthResponse)
async def health():
    return HealthResponse(
        status="ok",
        timestamp=datetime.now().isoformat(),
        sessions_active=len(SESSIONS),
    )


@app.get("/gremien", response_model=list[GremiumItem])
async def gremien(x_api_key: Optional[str] = Header(default=None)):
    verify_api_key(x_api_key)
    try:
        conn = psycopg2.connect(DSN)
        cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        # Alphabetisch sortiert. Simples ASCII-Sort, weil die DB mit SQL_ASCII-Encoding
        # läuft und die ICU-Collation daher nicht anwendbar ist. Für die aktuell
        # vorhandenen Gremien ohne Umlaut am Wortanfang reicht das.
        cur.execute("""
            SELECT gremium_key, gremium_name,
                   COUNT(*)          AS doc_count,
                   MAX(meeting_date) AS last_date
            FROM raw.documents
            WHERE gremium_key IS NOT NULL
            GROUP BY gremium_key, gremium_name
            ORDER BY gremium_name ASC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        return [
            GremiumItem(
                key=r["gremium_key"],
                name=r["gremium_name"],
                doc_count=r["doc_count"],
                last_date=str(r["last_date"]) if r["last_date"] else None,
            )
            for r in rows
        ]
    except Exception as e:
        log.error(f"DB-Fehler /gremien: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail="DB-Fehler")
