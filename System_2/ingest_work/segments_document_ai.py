# segments_document_ai.py
#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
segments_document_ai.py — Mistral OCR (Document AI) hint extraction for KommRAG

Goal
- Produce a hint-only segments.json in the final "Minutes_Segmented" shape:
    {
      "title": "...",
      "meta": { "tops_total": int, "tops_found": int, "agenda_hash": str|null, "notes": str|null, ... },
      "segments": [ {vorwort}, {top}..., {nachwort}, {anhang} ]
    }

Key facts
- DocAI does NOT produce PDF offset-space chars. Therefore:
  - start_char/end_char/end_page/end_char are always null here.
- Downstream (segments_pipeline.py) reconstructs spans deterministically from:
  - (start_page, hint_anchor, type, top_key)

Important anti-TOC guard
- We pass content_start_page (1-based) from pipeline.
- Any TOP start_page < content_start_page is rejected (set to null) to avoid TOC false-positives.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    import requests  # type: ignore
except Exception:  # pragma: no cover
    requests = None

from segments_utils import normalize_text_offset


# ----------------------------
# Agenda loading
# ----------------------------

@dataclass
class TopItem:
    key: str
    title: str
    drucksache: Optional[str] = None


def load_agenda(agenda_path: Path) -> List[TopItem]:
    data = json.loads(agenda_path.read_text(encoding="utf-8"))
    items: List[TopItem] = []

    def _add(it: Dict[str, Any]) -> None:
        key = (it.get("top_key_curated") or it.get("top_key") or it.get("key") or "").strip()
        title = (it.get("title_curated") or it.get("title") or it.get("name") or "").strip()
        dr = it.get("drucksache_curated") if "drucksache_curated" in it else it.get("drucksache")
        dr = (str(dr).strip() if isinstance(dr, str) else None)
        if key and title:
            items.append(TopItem(key=key, title=title, drucksache=dr))

    if isinstance(data, list):
        for x in data:
            if isinstance(x, dict):
                _add(x)
    elif isinstance(data, dict):
        for k in ("agenda", "items", "tops", "tagesordnung", "points", "tagesordnungspunkte"):
            v = data.get(k)
            if isinstance(v, list):
                for x in v:
                    if isinstance(x, dict):
                        _add(x)
                break

    return items


def agenda_tops_json(tops: List[TopItem]) -> str:
    arr = [{"top_key": t.key, "title": t.title, "drucksache": t.drucksache} for t in tops]
    return json.dumps(arr, ensure_ascii=False, separators=(",", ":"))


# ----------------------------
# Mistral OCR call
# ----------------------------

def _pdf_to_data_url(pdf_path: Path) -> str:
    b = pdf_path.read_bytes()
    b64 = base64.b64encode(b).decode("ascii")
    return f"data:application/pdf;base64,{b64}"


def _docai_schema(tops_total: int) -> Dict[str, Any]:
    seg_base = {
        "type": "object",
        "additionalProperties": False,
        "required": ["type", "title", "start_page", "hint_anchor", "evidence_quote", "text_full", "confidence", "match_method"],
        "properties": {
            "type": {"type": "string"},
            "title": {"type": "string"},
            "start_page": {"type": ["integer", "null"], "minimum": 1},
            "hint_anchor": {"type": ["string", "null"]},
            "evidence_quote": {"type": ["string", "null"]},
            "text_full": {"type": ["string", "null"]},
            "confidence": {"type": "number", "minimum": 0, "maximum": 1},
            "match_method": {"type": "string"},
            "start_char": {"type": ["integer", "null"], "minimum": 0},
            "end_page": {"type": ["integer", "null"], "minimum": 1},
            "end_char": {"type": ["integer", "null"], "minimum": 0},
        },
    }

    vorwort = dict(seg_base)
    vorwort["properties"] = dict(seg_base["properties"])
    vorwort["properties"]["type"] = {"const": "vorwort"}

    nachwort = dict(seg_base)
    nachwort["properties"] = dict(seg_base["properties"])
    nachwort["properties"]["type"] = {"const": "nachwort"}

    anhang = dict(seg_base)
    anhang["properties"] = dict(seg_base["properties"])
    anhang["properties"]["type"] = {"const": "anhang"}

    top = dict(seg_base)
    top["required"] = ["type", "top_key", "title", "start_page", "hint_anchor", "evidence_quote", "text_full", "confidence", "match_method"]
    top["properties"] = dict(seg_base["properties"])
    top["properties"]["type"] = {"const": "top"}
    top["properties"]["top_key"] = {"type": "string"}

    return {
        "type": "json_schema",
        "json_schema": {
            "name": "minutes_segments_schema",
            "schema": {
                "type": "object",
                "additionalProperties": False,
                "required": ["title", "meta", "segments"],
                "properties": {
                    "title": {"type": "string"},
                    "meta": {
                        "type": "object",
                        "additionalProperties": False,
                        "required": ["tops_total", "tops_found", "agenda_hash", "notes"],
                        "properties": {
                            "tops_total": {"type": "integer", "minimum": 0, "maximum": max(0, tops_total)},
                            "tops_found": {"type": "integer", "minimum": 0},
                            "agenda_hash": {"type": ["string", "null"]},
                            "notes": {"type": ["string", "null"]},
                        },
                    },
                    "segments": {
                        "type": "array",
                        "minItems": 1,
                        "items": {"oneOf": [vorwort, top, nachwort, anhang]},
                    },
                },
            },
        },
    }


def _docai_prompt(
    agenda_json_str: str,
    tops_total: int,
    agenda_hash: Optional[str],
    content_start_page: Optional[int],
) -> str:
    ah = (
        agenda_hash
        if agenda_hash
        else "<WIRD VOM CALLER VORAB BERECHNET UND HIER ALS KONSTANTER STRING EINGESETZT>"
    )
    csp = int(content_start_page) if isinstance(content_start_page, int) and content_start_page > 0 else None

    csp_line = (
        f"content_start_page = {csp} (1-basiert). Alle Seiten davor sind Deckblatt/TOC/Meta und dürfen NICHT für TOP-Anker verwendet werden.\n"
        if csp
        else ""
    )

    csp_rule = (
        f"   Für TOPs: start_page MUSS >= {csp} sein. Andernfalls start_page=null.\n\n"
        if csp
        else "\n"
    )

    return (
        "Du erhältst den OCR-Text (alle Seiten, 1-basiert) eines deutschen Sitzungsprotokolls.\n"
        "Deine Aufgabe: Erzeuge Segmente (vorwort, TOPs, nachwort, anhang) mit start_page und einem robusten hint_anchor.\n\n"
        "WICHTIG: Verwechslungen mit Tagesordnung/TOC müssen aktiv vermieden werden.\n\n"

        "Kontext:\n"
        f"{csp_line}\n"

        "Harte Regeln:\n"
        "1) Ignoriere Deckblatt, Kopfseiten, Anlagenverzeichnisse und die Tagesordnung/Inhaltsverzeichnis am Anfang.\n"
        "   Suche im laufenden Protokolltext (typisch dort, wo Seitenzählungen wie \"- 4 -\" erscheinen).\n"
        "   Wenn du nur die TOC/Tagesordnung findest: gib start_page=null (nicht raten).\n\n"

        "2) Segment-Reihenfolge:\n"
        "   a) GENAU EIN vorwort-Objekt (falls kein echter Protokolltext vor TOP 1 existiert: start_page=null, hint_anchor=null, confidence<=0.3, match_method=\"not_found\").\n"
        "   b) Danach GENAU alle TOP-Objekte in Agenda-Reihenfolge (siehe agenda_tops).\n"
        "   c) Danach GENAU EIN nachwort-Objekt (falls nicht vorhanden: start_page=null, hint_anchor=null, confidence<=0.3, match_method=\"not_found\").\n"
        "   d) Danach GENAU EIN anhang-Objekt (falls kein echter Anhang/Anlagenbereich: start_page=null, hint_anchor=null, confidence<=0.3, match_method=\"not_found\").\n\n"

        "3) TOP-Start-Definition (entscheidend gegen Verwechslungen):\n"
        "   Ein TOP gilt nur als gefunden, wenn im OCR-Text eine Zeile am Zeilenanfang mit dem TOP-Marker beginnt (z.B. \"1.\" \"22.3\" \"19.a\"),\n"
        "   und in derselben Zeile oder in den direkt folgenden 1–2 Zeilen der Agenda-Titel eindeutig erkennbar ist.\n"
        "   Ein Eintrag in der Tagesordnung/TOC zählt NICHT als gefunden.\n"
        "   Unterüberschriften innerhalb eines TOP sind NICHT der TOP-Start.\n\n"

        "4) start_page:\n"
        "   1-basiert. Muss monoton nicht fallend sein (darf gleich bleiben).\n"
        + csp_rule +

        "5) hint_anchor (kritisch):\n"
        "   1 bis 3 vollständige OCR-Zeilen, VERBATIM, exakt wie im OCR-Text.\n"
        "   Muss für TOPs mindestens eine Zeile enthalten, die mit dem TOP-Marker beginnt.\n"
        "   Verboten in hint_anchor: Markdown-Header-Zeichen (#), Aufzählungszeichen (*, -, •) als künstliche Formatierung.\n"
        "   Wähle Zeilen ohne solche Zeichen. Keine Paraphrasen.\n\n"

        "6) evidence_quote:\n"
        "   Wenn möglich 1–2 Sätze aus dem Inhalt direkt nach dem Start (nicht aus dem Titel), ebenfalls wörtlich.\n"
        "   Muss aus dem laufenden Protokolltext stammen, NICHT aus der Tagesordnung/TOC.\n\n"

        "7) text_full:\n"
        "   Setze text_full entweder null oder (bevorzugt) nur die eine TOP-Überschriftzeile.\n"
        "   NIEMALS große TOC-Blöcke oder Listen in text_full einfügen.\n\n"

        f"Meta-Vorgaben:\n"
        f"tops_total = {tops_total}\n"
        f"agenda_hash = \"{ah}\"\n"
        "tops_found = Anzahl TOPs mit start_page != null\n\n"

        "agenda_tops (Reihenfolge verbindlich, top_key exakt übernehmen):\n"
        f"agenda_tops = {agenda_json_str}\n\n"

        "Ausgabe: ausschließlich JSON gemäß Schema."
    )

def call_mistral_ocr(
    pdf_path: Path,
    *,
    api_key: str,
    model: str,
    url: str,
    timeout_s: int,
    annotation_schema: Dict[str, Any],
    annotation_prompt: str,
    include_image_base64: bool = False,
) -> Dict[str, Any]:
    if requests is None:
        raise RuntimeError("requests ist nicht verfügbar. Installiere: pip install requests")

    payload: Dict[str, Any] = {
        "document": {"type": "document_url", "document_url": _pdf_to_data_url(pdf_path)},
        "model": model,
        "include_image_base64": bool(include_image_base64),
        "document_annotation_format": annotation_schema,
        "document_annotation_prompt": annotation_prompt,
    }

    headers = {"Content-Type": "application/json", "Authorization": f"Bearer {api_key}"}
    r = requests.post(url, headers=headers, json=payload, timeout=timeout_s)
    if r.status_code >= 400:
        raise RuntimeError(f"Mistral OCR HTTP {r.status_code}: {r.text[:1200]}")
    return r.json()


def extract_annotation(raw: Dict[str, Any]) -> Dict[str, Any]:
    ann = raw.get("document_annotation")

    # Helper: try to extract a JSON object substring from a messy string
    def _extract_json_object_substring(s: str) -> Optional[str]:
        if not s:
            return None
        t = s.strip()
        i = t.find("{")
        j = t.rfind("}")
        if i != -1 and j != -1 and j > i:
            return t[i:j+1]
        return None

    if isinstance(ann, str):
        # 1) direct parse
        try:
            ann_obj = json.loads(ann)
        except Exception:
            # 2) try parse extracted {...} block
            sub = _extract_json_object_substring(ann)
            if sub:
                try:
                    ann_obj = json.loads(sub)
                except Exception:
                    ann_obj = None
            else:
                ann_obj = None

            # 3) if still broken: soft-fail (do NOT crash the pipeline)
            if ann_obj is None:
                try:
                    Path("docai_annotation_broken.txt").write_text(ann, encoding="utf-8", errors="ignore")
                except Exception:
                    pass
                return {
                    "title": "Minutes",
                    "meta": {"tops_total": 0, "tops_found": 0, "agenda_hash": None, "notes": "docai_document_annotation_unparseable"},
                    "segments": [],
                }

    elif isinstance(ann, dict):
        ann_obj = ann
    else:
        # soft-fail (no annotation)
        return {
            "title": "Minutes",
            "meta": {"tops_total": 0, "tops_found": 0, "agenda_hash": None, "notes": "docai_document_annotation_missing"},
            "segments": [],
        }

    # If shape is wrong, also soft-fail
    if not isinstance(ann_obj, dict) or "segments" not in ann_obj or "meta" not in ann_obj:
        return {
            "title": "Minutes",
            "meta": {"tops_total": 0, "tops_found": 0, "agenda_hash": None, "notes": "docai_document_annotation_wrong_shape"},
            "segments": [],
        }

    return ann_obj

# ----------------------------
# Anchor sanitizing / helpers
# ----------------------------

_FORBIDDEN_ANCHOR_PREFIX = re.compile(r"^\s*(#+|\*+|\-+|•+)\s*")
_FORBIDDEN_ANY = re.compile(r"[#]")


def _split_lines(s: str) -> List[str]:
    return [ln.rstrip("\r") for ln in (s or "").splitlines()]


def sanitize_hint_anchor(anchor: Optional[str], *, max_lines: int = 3) -> Tuple[Optional[str], float, str]:
    if not isinstance(anchor, str):
        return None, 0.7, "anchor_missing"

    a = normalize_text_offset(anchor)
    if not a:
        return None, 0.7, "anchor_empty"

    lines = [ln.strip() for ln in _split_lines(a) if ln.strip()]
    if not lines:
        return None, 0.7, "anchor_no_lines"

    good: List[str] = []
    for ln in lines:
        if _FORBIDDEN_ANCHOR_PREFIX.search(ln):
            continue
        if _FORBIDDEN_ANY.search(ln):
            continue
        good.append(ln)
        if len(good) >= max_lines:
            break

    if good:
        return "\n".join(good), 1.0, "anchor_clean"

    ok2: List[str] = []
    for ln in lines:
        if _FORBIDDEN_ANCHOR_PREFIX.search(ln):
            continue
        ok2.append(ln)
        if len(ok2) >= max_lines:
            break
    if ok2:
        return "\n".join(ok2), 0.85, "anchor_has_hash"

    fallback = "\n".join(lines[:max_lines])
    return fallback, 0.75, "anchor_fallback"


def _force_null_span_fields(seg: Dict[str, Any]) -> None:
    seg["start_char"] = None
    seg["end_page"] = None
    seg["end_char"] = None


def _coerce_conf(x: Any, default: float) -> float:
    try:
        v = float(x)
        if v != v:
            return default
        return max(0.0, min(1.0, v))
    except Exception:
        return default


# ----------------------------
# Post-processing to enforce exact output contract
# ----------------------------

def normalize_output_shape(
    ann_obj: Dict[str, Any],
    *,
    tops: List[TopItem],
    agenda_hash: Optional[str],
    content_start_page: Optional[int],
) -> Dict[str, Any]:
    out: Dict[str, Any] = {"title": ann_obj.get("title") or "Minutes", "meta": {}, "segments": []}

    meta_in = ann_obj.get("meta") if isinstance(ann_obj.get("meta"), dict) else {}
    out_meta: Dict[str, Any] = {
        "tops_total": len(tops),
        "tops_found": 0,
        "agenda_hash": agenda_hash if agenda_hash is not None else (meta_in.get("agenda_hash") if isinstance(meta_in.get("agenda_hash"), str) else None),
        "notes": meta_in.get("notes") if isinstance(meta_in.get("notes"), (str, type(None))) else None,
    }
    if isinstance(content_start_page, int) and content_start_page > 0:
        out_meta["content_start_page"] = int(content_start_page)
    out["meta"] = out_meta

    segs_in = ann_obj.get("segments") if isinstance(ann_obj.get("segments"), list) else []

    vorwort_in: Optional[Dict[str, Any]] = None
    nachwort_in: Optional[Dict[str, Any]] = None
    anhang_in: Optional[Dict[str, Any]] = None
    tops_map: Dict[str, Dict[str, Any]] = {}

    for s in segs_in:
        if not isinstance(s, dict):
            continue
        st = (s.get("type") or "").strip().lower()
        if st == "vorwort" and vorwort_in is None:
            vorwort_in = s
        elif st == "nachwort" and nachwort_in is None:
            nachwort_in = s
        elif st == "anhang" and anhang_in is None:
            anhang_in = s
        elif st == "top":
            k = (s.get("top_key") or "").strip()
            if k and k not in tops_map:
                tops_map[k] = s

    csp = int(content_start_page) if isinstance(content_start_page, int) and content_start_page > 0 else None

    def _build_non_top(stype: str, title: str, src: Optional[Dict[str, Any]]) -> Dict[str, Any]:
        start_page = src.get("start_page") if isinstance(src, dict) else None
        hint = src.get("hint_anchor") if isinstance(src, dict) else None
        ev = src.get("evidence_quote") if isinstance(src, dict) else None
        tf = src.get("text_full") if isinstance(src, dict) else None
        conf = _coerce_conf(src.get("confidence") if isinstance(src, dict) else None, 0.3)
        mm = (src.get("match_method") if isinstance(src, dict) else None) or ("docai_annotation" if start_page else "not_found")

        hint2, mult, note = sanitize_hint_anchor(hint)
        conf = _coerce_conf(conf * mult, conf)

        if start_page is None:
            conf = min(conf, 0.3)
            mm = "not_found"
            hint2 = None

        # For non-top, we do NOT hard-reject < content_start_page (vorwort may legitimately be near start).
        seg: Dict[str, Any] = {
            "type": stype,
            "title": title,
            "start_page": start_page if isinstance(start_page, int) and start_page > 0 else None,
            "hint_anchor": hint2,
            "evidence_quote": normalize_text_offset(ev) if isinstance(ev, str) and ev.strip() else None,
            "text_full": normalize_text_offset(tf) if isinstance(tf, str) and tf.strip() else None,
            "confidence": conf,
            "match_method": str(mm),
        }
        _force_null_span_fields(seg)
        if note != "anchor_clean":
            out_meta["notes"] = (out_meta["notes"] or "")
            if out_meta["notes"]:
                out_meta["notes"] += " | "
            out_meta["notes"] += f"{stype}:anchor={note}"
        return seg

    out_segs: List[Dict[str, Any]] = []
    out_segs.append(_build_non_top("vorwort", "Protokollkopf", vorwort_in))

    prev_p: Optional[int] = None
    for t in tops:
        src = tops_map.get(t.key)
        start_page = src.get("start_page") if isinstance(src, dict) else None
        hint = src.get("hint_anchor") if isinstance(src, dict) else None
        ev = src.get("evidence_quote") if isinstance(src, dict) else None
        tf = src.get("text_full") if isinstance(src, dict) else None
        conf = _coerce_conf(src.get("confidence") if isinstance(src, dict) else None, 0.3)
        mm = (src.get("match_method") if isinstance(src, dict) else None) or ("docai_annotation" if start_page else "not_found")

        hint2, mult, note = sanitize_hint_anchor(hint)
        conf = _coerce_conf(conf * mult, conf)

        if not isinstance(start_page, int) or start_page <= 0:
            start_page = None
            hint2 = None
            conf = min(conf, 0.3)
            mm = "not_found"
        else:
            # Hard anti-TOC gate: reject TOPs before content_start_page
            if csp is not None and start_page < csp:
                start_page = None
                hint2 = None
                conf = min(conf, 0.3)
                mm = "blocked_before_content_start_page"
                out_meta["notes"] = (out_meta["notes"] or "")
                if out_meta["notes"]:
                    out_meta["notes"] += " | "
                out_meta["notes"] += f"top:{t.key}:blocked_before_csp({csp})"
            else:
                if prev_p is not None and start_page < prev_p:
                    start_page = prev_p
                    conf = min(conf, 0.75)
                    mm = str(mm) + "+clamped_monotonic"
                prev_p = start_page

        seg: Dict[str, Any] = {
            "type": "top",
            "top_key": t.key,
            "title": t.title,
            "start_page": start_page,
            "hint_anchor": hint2,
            "evidence_quote": normalize_text_offset(ev) if isinstance(ev, str) and ev.strip() else None,
            "text_full": normalize_text_offset(tf) if isinstance(tf, str) and tf.strip() else None,
            "confidence": conf,
            "match_method": str(mm),
        }
        _force_null_span_fields(seg)

        if note != "anchor_clean":
            out_meta["notes"] = (out_meta["notes"] or "")
            if out_meta["notes"]:
                out_meta["notes"] += " | "
            out_meta["notes"] += f"top:{t.key}:anchor={note}"

        out_segs.append(seg)

    out_segs.append(_build_non_top("nachwort", "Schluss der Sitzung", nachwort_in))
    out_segs.append(_build_non_top("anhang", "Anlagen / Anhang", anhang_in))

    tfound = 0
    for s in out_segs:
        if s.get("type") == "top" and isinstance(s.get("start_page"), int):
            tfound += 1
    out_meta["tops_found"] = tfound

    out["segments"] = out_segs
    return out


# ----------------------------
# Main
# ----------------------------

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--agenda", required=True)
    ap.add_argument("--out", required=True)

    ap.add_argument("--agenda_hash", default="", help="agenda_hash provided by caller (preferred).")
    ap.add_argument("--docai_result_json", default="", help="Use existing annotation JSON (already in target shape).")
    ap.add_argument("--cache_docai_json", default="", help="Write raw OCR API response here for debugging.")

    ap.add_argument("--api_key", default="", help="Mistral API key (or env MISTRAL_API_KEY).")
    ap.add_argument("--model", default=os.getenv("MISTRAL_OCR_MODEL", "mistral-ocr-latest"))
    ap.add_argument("--url", default=os.getenv("MISTRAL_OCR_URL", "https://api.mistral.ai/v1/ocr"))
    ap.add_argument("--timeout_s", type=int, default=240)
    ap.add_argument("--include_image_base64", action="store_true")

    ap.add_argument("--force_full_pdf", action="store_true", help="Kept for compatibility (currently unused).")
    
    ap.add_argument(
    "--docai_pages_json",
    default="",
    help="DUMMY: optional path to pre-extracted per-page OCR dump (ignored in this script).",
    )

    # Anti-TOC gate from pipeline
    ap.add_argument(
        "--content_start_page",
        type=int,
        default=0,
        help="1-based page index where the actual minutes body text starts. TOP anchors before this are rejected.",
    )

    args = ap.parse_args()

    pdf_path = Path(args.pdf)
    agenda_path = Path(args.agenda)
    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    tops = load_agenda(agenda_path)
    if not tops:
        raise SystemExit("Agenda ist leer oder nicht erkannt.")

    agenda_hash = args.agenda_hash.strip() or None
    tops_total = len(tops)
    csp = int(args.content_start_page) if int(args.content_start_page) > 0 else None

    if args.docai_result_json:
        ann_obj = json.loads(Path(args.docai_result_json).read_text(encoding="utf-8"))
        if not isinstance(ann_obj, dict):
            raise SystemExit("docai_result_json ist kein JSON-Objekt.")
        if "segments" not in ann_obj or "meta" not in ann_obj:
            raise SystemExit("docai_result_json: meta/segments fehlen.")
        source = "docai_result_json"
    else:
        api_key = args.api_key.strip() or os.getenv("MISTRAL_API_KEY", "").strip()
        if not api_key:
            raise SystemExit("Kein MISTRAL_API_KEY gesetzt (env oder --api_key).")

        schema = _docai_schema(tops_total=tops_total)
        prompt = _docai_prompt(
            agenda_json_str=agenda_tops_json(tops),
            tops_total=tops_total,
            agenda_hash=agenda_hash,
            content_start_page=csp,
        )

        raw = call_mistral_ocr(
            pdf_path,
            api_key=api_key,
            model=args.model,
            url=args.url,
            timeout_s=int(args.timeout_s),
            annotation_schema=schema,
            annotation_prompt=prompt,
            include_image_base64=bool(args.include_image_base64),
        )
        source = "mistral_ocr_api"

        if args.cache_docai_json:
            Path(args.cache_docai_json).write_text(json.dumps(raw, ensure_ascii=False, indent=2), encoding="utf-8")

        ann_obj = extract_annotation(raw)

    out_payload = normalize_output_shape(
        ann_obj,
        tops=tops,
        agenda_hash=agenda_hash,
        content_start_page=csp,
    )

    meta = out_payload.get("meta", {})
    if isinstance(meta, dict):
        meta["notes"] = (meta.get("notes") or "")
        if meta["notes"]:
            meta["notes"] += " | "
        meta["notes"] += f"source={source}"
        meta["agenda_hash"] = agenda_hash if agenda_hash is not None else meta.get("agenda_hash")

    out_json = out_dir / "segments.json"
    out_json.write_text(json.dumps(out_payload, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps(
        {
            "ok": True,
            "out": str(out_json),
            "source": source,
            "tops_total": tops_total,
            "tops_found": int(out_payload.get("meta", {}).get("tops_found", 0)) if isinstance(out_payload.get("meta"), dict) else None,
            "content_start_page": csp,
        },
        ensure_ascii=False
    ))


if __name__ == "__main__":
    main()