#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import csv
import json
import os
import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional, Tuple, List, Dict, Any


REQUIRED_FILES = [
    "agenda.json",
    "attendance.json",
    "core.json",
    "documents.json",
    "segments.json",
    "ready.done",
]

LOG_FIELDS = [
    "ts", "package_id", "src", "dst", "decision",
    "reason_code", "tops_found", "tops_total", "details",
]


@dataclass
class Decision:
    dst_dir: str              # "done" | "failed" | "skip"
    decision: str             # "accept" | "reject" | "skip"
    reason_code: str
    tops_found: Optional[int] = None
    tops_total: Optional[int] = None
    details: str = ""


def utc_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def ensure_dir(p: Path) -> None:
    p.mkdir(parents=True, exist_ok=True)


def append_csv(csv_path: Path, row: Dict[str, str]) -> None:
    ensure_dir(csv_path.parent)
    file_exists = csv_path.exists()
    with csv_path.open("a", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=LOG_FIELDS)
        if not file_exists:
            w.writeheader()
        w.writerow(row)


def find_pdf(package_dir: Path) -> Optional[Path]:
    # Nimm die erste PDF im Paket (ggf. kannst du das später härter machen)
    pdfs = sorted(package_dir.glob("*.pdf"))
    return pdfs[0] if pdfs else None


def read_json(path: Path) -> Optional[Dict[str, Any]]:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None


def check_required_files(package_dir: Path) -> Optional[str]:
    for rel in REQUIRED_FILES:
        if not (package_dir / rel).is_file():
            return rel
    if find_pdf(package_dir) is None:
        return "<pdf>"
    return None


def evaluate_segments(package_dir: Path) -> Decision:
    # Gate: only if ready.done exists (sonst skip)
    if not (package_dir / "ready.done").is_file():
        return Decision(dst_dir="skip", decision="skip", reason_code="not_ready",
                        details="ready.done missing; leaving package in incoming.")

    missing = check_required_files(package_dir)
    if missing is not None:
        return Decision(
            dst_dir="failed",
            decision="reject",
            reason_code="missing_required_file",
            details=f"Missing required: {missing}",
        )

    seg_path = package_dir / "segments.json"
    data = read_json(seg_path)
    if not isinstance(data, dict):
        return Decision(dst_dir="failed", decision="reject",
                        reason_code="invalid_segments_json",
                        details="segments.json not parseable JSON.")

    meta = data.get("meta") if isinstance(data.get("meta"), dict) else {}
    segments = data.get("segments") if isinstance(data.get("segments"), list) else []

    tops_total = meta.get("tops_total")
    tops_found = meta.get("tops_found")

    try:
        tops_total_i = int(tops_total)
    except Exception:
        tops_total_i = None
    try:
        tops_found_i = int(tops_found)
    except Exception:
        tops_found_i = None

    if not tops_total_i or tops_total_i <= 0:
        return Decision(dst_dir="failed", decision="reject",
                        reason_code="tops_total_invalid",
                        tops_found=tops_found_i, tops_total=tops_total_i,
                        details="meta.tops_total missing/invalid.")

    if tops_found_i is None:
        return Decision(dst_dir="failed", decision="reject",
                        reason_code="tops_found_missing",
                        tops_found=tops_found_i, tops_total=tops_total_i,
                        details="meta.tops_found missing/invalid.")

    if tops_found_i != tops_total_i:
        return Decision(dst_dir="failed", decision="reject",
                        reason_code="tops_incomplete",
                        tops_found=tops_found_i, tops_total=tops_total_i,
                        details=f"tops_found({tops_found_i}) != tops_total({tops_total_i}).")

    # Zusätzliche harte Checks auf Segmentebene:
    # - Kein TOP darf missing sein
    missing_tops = []
    empty_text_tops = 0

    for s in segments:
        if not isinstance(s, dict):
            continue
        if s.get("type") != "top":
            continue

        if s.get("match_method") == "missing" or s.get("start_page") is None:
            missing_tops.append(str(s.get("top_key") or "?"))

        txt = s.get("text_full")
        if isinstance(txt, str) and txt.strip() == "":
            empty_text_tops += 1

    if missing_tops:
        return Decision(dst_dir="failed", decision="reject",
                        reason_code="top_segments_missing",
                        tops_found=tops_found_i, tops_total=tops_total_i,
                        details=f"Missing TOP segments: {', '.join(missing_tops[:10])}")

    # Optional: wenn du text_full zwingend willst, mach daraus ein Reject.
    # Ich setze es erstmal nur als Warn-Info in details.
    details = "OK"
    if empty_text_tops > 0:
        details = f"OK (warning: {empty_text_tops} TOPs have empty text_full)"

    return Decision(dst_dir="done", decision="accept",
                    reason_code="ok",
                    tops_found=tops_found_i, tops_total=tops_total_i,
                    details=details)


def move_package(src: Path, dst_root: Path) -> Path:
    dst = dst_root / src.name
    if dst.exists():
        raise RuntimeError(f"Target already exists: {dst}")
    shutil.move(str(src), str(dst))
    return dst


def triage(root: Path, csv_path: Path) -> Tuple[int, int, int, int]:
    incoming = root / "incoming"
    done = root / "done"
    failed = root / "failed"

    ensure_dir(done)
    ensure_dir(failed)

    if not incoming.is_dir():
        raise RuntimeError(f"Missing directory: {incoming}")

    total = moved_done = moved_failed = skipped = 0

    for pkg in sorted([p for p in incoming.iterdir() if p.is_dir()]):
        total += 1
        d = evaluate_segments(pkg)

        if d.dst_dir == "skip":
            skipped += 1
            # optional: log skip
            append_csv(csv_path, {
                "ts": utc_iso(),
                "package_id": pkg.name,
                "src": "incoming",
                "dst": "incoming",
                "decision": d.decision,
                "reason_code": d.reason_code,
                "tops_found": "" if d.tops_found is None else str(d.tops_found),
                "tops_total": "" if d.tops_total is None else str(d.tops_total),
                "details": d.details,
            })
            print(f"[{pkg.name}] skip ({d.reason_code})")
            continue

        target_root = done if d.dst_dir == "done" else failed
        moved_to = move_package(pkg, target_root)

        append_csv(csv_path, {
            "ts": utc_iso(),
            "package_id": pkg.name,
            "src": "incoming",
            "dst": d.dst_dir,
            "decision": d.decision,
            "reason_code": d.reason_code,
            "tops_found": "" if d.tops_found is None else str(d.tops_found),
            "tops_total": "" if d.tops_total is None else str(d.tops_total),
            "details": d.details,
        })

        if d.dst_dir == "done":
            moved_done += 1
        else:
            moved_failed += 1

        print(f"[{pkg.name}] -> {d.dst_dir} ({d.reason_code})")

    return total, moved_done, moved_failed, skipped


if __name__ == "__main__":
    import argparse

    ap = argparse.ArgumentParser()
    ap.add_argument("--root", required=True, help="Root with incoming/done/failed")
    ap.add_argument("--csv", default="logs/triage.csv", help="CSV log path (relative to root if not absolute)")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    csv_path = Path(args.csv)
    if not csv_path.is_absolute():
        csv_path = root / csv_path

    total, ok, bad, skip = triage(root, csv_path)
    print(f"Total={total} done={ok} failed={bad} skipped={skip}")