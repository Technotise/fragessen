#!/usr/bin/env python3
import sys
import os
import json
import re
from typing import List, Dict, Optional, Any

def die(code: int, err: str, msg: str = "") -> None:
    print(json.dumps({"error": err, "message": msg}, ensure_ascii=False))
    sys.exit(code)

def norm(s: str) -> str:
    s = s.replace("\r\n", "\n").replace("\r", "\n")
    s = re.sub(r"[ \t]+", " ", s)
    s = re.sub(r"\n{3,}", "\n\n", s)
    return s.strip()

def lines(s: str) -> List[str]:
    s = s.replace("\r\n", "\n").replace("\r", "\n")
    out: List[str] = []
    for ln in s.split("\n"):
        t = ln.strip()
        if t:
            out.append(t)
    return out

def find_top1_page(page_texts: Dict[int, str], agenda_page: int, kw: List[str]) -> Dict[str, Any]:
    scanN = max(page_texts.keys()) if page_texts else 0
    hits: List[str] = []

    for p in range(min(agenda_page + 1, scanN), scanN + 1):
        ls = lines(page_texts.get(p, ""))
        n = len(ls)

        i = 0
        while i < n:
            ln = ls[i]
            looks_top1 = False

            # Spezialfall: "a. Öffentlicher Teil" -> danach nach "1.) ..." suchen
            if re.search(r"^\s*a\.\s*öffentlicher\s+teil\b", ln, re.I):
                for j in range(i + 1, min(n, i + 20)):
                    if re.search(r"^\s*1\s*[\.\)]\s+\S+", ls[j]):
                        looks_top1 = True
                        i = j
                        break

            if (not looks_top1) and re.search(r"^\s*1\s*[\.\)]\s+\S+", ln):
                looks_top1 = True
            if (not looks_top1) and re.search(r"^\s*top\s*1\b", ln, re.I):
                looks_top1 = True

            if not looks_top1:
                i += 1
                continue

            window = "\n".join(ls[i:i+35]).lower()
            for k in kw:
                kl = (k or "").strip().lower()
                if kl and kl in window:
                    hits.append(k)

            hits = sorted(set(hits))
            return {"top1_page": p, "top1_line": i + 1, "kw_hits": hits}

    return {"top1_page": None, "top1_line": None, "kw_hits": []}

def do_detect(pdf_path: str, scan_pages: int, kw: List[str]) -> Dict[str, Any]:
    try:
        from pypdf import PdfReader
    except Exception as e:
        die(1, "import_error", str(e))

    if not os.path.isfile(pdf_path):
        die(2, "src_not_found", pdf_path)

    reader = PdfReader(pdf_path)
    total = len(reader.pages)

    scanN = 0
    if total > 0:
        scanN = min(max(1, int(scan_pages)), total)

    page_texts: Dict[int, str] = {}
    agenda_page: Optional[int] = None

    for p in range(1, scanN + 1):
        try:
            txt = reader.pages[p-1].extract_text() or ""
        except Exception:
            txt = ""
        txt = norm(txt)
        page_texts[p] = txt
        if agenda_page is None and re.search(r"\btagesordnung\b", txt, re.I):
            agenda_page = p

    top = {"top1_page": None, "top1_line": None, "kw_hits": []}
    if agenda_page is not None:
        top = find_top1_page(page_texts, agenda_page, kw)

    return {
        "ok": True,
        "pages_total": total,
        "pages_scanned": scanN,
        "agenda_page": agenda_page,
        "top1_page": top["top1_page"],
        "top1_line": top["top1_line"],
        "kw_hits": top["kw_hits"],
    }

def do_slice(src: str, dst: str, end_page_1based: int) -> Dict[str, Any]:
    try:
        from pypdf import PdfReader, PdfWriter
    except Exception as e:
        die(1, "import_error", str(e))

    if end_page_1based <= 0:
        die(2, "invalid_end_page")

    if not os.path.isfile(src):
        die(2, "src_not_found", src)

    reader = PdfReader(src)
    total = len(reader.pages)
    n = min(int(end_page_1based), total)

    writer = PdfWriter()
    for i in range(n):
        writer.add_page(reader.pages[i])

    out_dir = os.path.dirname(dst)
    if out_dir and not os.path.isdir(out_dir):
        os.makedirs(out_dir, exist_ok=True)

    tmp = dst + ".tmp"
    if os.path.exists(tmp):
        try:
            os.remove(tmp)
        except Exception:
            pass

    with open(tmp, "wb") as f:
        writer.write(f)

    os.replace(tmp, dst)

    return {"ok": True, "pages_total": total, "pages_written": n, "dst": dst}

def main() -> None:
    # Neu: detect
    #   slice_pdf.py detect --pdf <pdf> [--scan_pages N] [--kw <k>]...
    # Alt: slice
    #   slice_pdf.py <src_pdf> <dst_pdf> <end_page_1based>
    if len(sys.argv) >= 2 and sys.argv[1] == "detect":
        pdf: Optional[str] = None
        scan_pages = 25
        kw: List[str] = []

        i = 2
        while i < len(sys.argv):
            a = sys.argv[i]
            if a == "--pdf" and i + 1 < len(sys.argv):
                pdf = sys.argv[i + 1]
                i += 2
                continue
            if a == "--scan_pages" and i + 1 < len(sys.argv):
                try:
                    scan_pages = int(sys.argv[i + 1])
                except Exception:
                    die(2, "invalid_scan_pages")
                i += 2
                continue
            if a == "--kw" and i + 1 < len(sys.argv):
                kw.append(sys.argv[i + 1])
                i += 2
                continue

            die(2, "bad_args", f"unknown or incomplete arg: {a}")

        if not pdf:
            die(2, "missing_args", "usage: slice_pdf.py detect --pdf <pdf> [--scan_pages N] [--kw <k>]...")

        try:
            res = do_detect(pdf, scan_pages, kw)
            print(json.dumps(res, ensure_ascii=False))
            sys.exit(0)
        except SystemExit:
            raise
        except Exception as e:
            die(1, "exception", str(e))

    # Fallback: alter Slice-Modus (abwärtskompatibel)
    if len(sys.argv) < 4:
        die(2, "missing_args", "usage: slice_pdf.py <src_pdf> <dst_pdf> <end_page_1based>")

    src = sys.argv[1]
    dst = sys.argv[2]
    try:
        end_page = int(sys.argv[3])
    except Exception:
        die(2, "invalid_end_page")

    try:
        res = do_slice(src, dst, end_page)
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0)
    except SystemExit:
        raise
    except Exception as e:
        die(1, "exception", str(e))

if __name__ == "__main__":
    main()
