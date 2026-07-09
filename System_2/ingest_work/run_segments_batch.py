#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import argparse
import os
import re
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path
from typing import List, Optional


@dataclass
class PackageJob:
    package_dir: Path
    pdf_path: Path
    agenda_path: Path


def _tail(s: str, n: int = 4000) -> str:
    s = (s or "").strip()
    return s if len(s) <= n else s[-n:]


def _is_pdf(p: Path) -> bool:
    return p.is_file() and p.suffix.lower() == ".pdf"


def _find_pdf_in_dir(d: Path, prefer_regex: Optional[re.Pattern] = None) -> Optional[Path]:
    pdfs = [p for p in d.iterdir() if _is_pdf(p)]
    if not pdfs:
        return None
    if prefer_regex:
        for p in sorted(pdfs):
            if prefer_regex.search(p.name):
                return p
    return sorted(pdfs)[0]


def _find_jobs(root: Path, agenda_name: str, pdf_name: str, pdf_prefer: str, max_depth: int) -> List[PackageJob]:
    jobs: List[PackageJob] = []
    prefer_rx = re.compile(pdf_prefer, re.IGNORECASE) if pdf_prefer else None
    root = root.resolve()

    def depth_of(p: Path) -> int:
        try:
            return len(p.relative_to(root).parts)
        except Exception:
            return 999999

    for dirpath, dirnames, filenames in os.walk(root):
        d = Path(dirpath)

        if max_depth >= 0 and depth_of(d) > max_depth:
            dirnames[:] = []
            continue

        if agenda_name in filenames:
            agenda = d / agenda_name

            pdf: Optional[Path] = None
            if pdf_name:
                cand = d / pdf_name
                if cand.exists():
                    pdf = cand
            if pdf is None:
                pdf = _find_pdf_in_dir(d, prefer_rx)

            if pdf is None:
                continue

            jobs.append(PackageJob(package_dir=d, pdf_path=pdf, agenda_path=agenda))

    jobs.sort(key=lambda j: str(j.package_dir).lower())
    return jobs


def _run_one(
    job: PackageJob,
    python_exe: str,
    pipeline_py: Path,
    segmenter_py: Path,
    threshold: float,
    use_docai: bool,
    keep_temp: bool,
    patch_in_validator: bool,
    docai_timeout_s: int,
    docai_force_full_pdf: bool,
    log_csv: Path,
    log_jsonl: Path,
) -> int:
    cmd = [
        python_exe,
        str(pipeline_py),
        "--pdf", str(job.pdf_path),
        "--agenda", str(job.agenda_path),
        "--segmenter", str(segmenter_py),
        "--threshold", str(threshold),
        "--log_csv", str(log_csv),
        "--log_jsonl", str(log_jsonl),
        "--docai_timeout_s", str(int(docai_timeout_s)),
    ]
    if use_docai:
        cmd.append("--use_docai")
    if keep_temp:
        cmd.append("--keep_temp")
    if patch_in_validator:
        cmd.append("--patch_in_validator")
    if docai_force_full_pdf:
        cmd.append("--docai_force_full_pdf")

    p = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        cwd=str(job.package_dir),
        env=os.environ.copy(),
    )
    if p.returncode != 0:
        print(f"FAIL: {job.package_dir} (rc={p.returncode})")
        print(_tail(p.stdout, 8000))
        print("")
    return int(p.returncode)


def main() -> None:
    here = Path(__file__).resolve().parent

    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=".", help="Root folder containing package dirs (default: .)")
    ap.add_argument("--pipeline", default="", help="Path to segments_pipeline.py (default: next to this script)")
    ap.add_argument("--segmenter", default="", help="Path to segments.py (default: next to this script)")

    ap.add_argument("--agenda_name", default="agenda.json")
    ap.add_argument("--pdf_name", default="")
    ap.add_argument("--pdf_prefer", default=r"Protokollunterlagen|Niederschrift|Protokoll")
    ap.add_argument("--max_depth", type=int, default=2)

    ap.add_argument("--python", default=sys.executable)
    ap.add_argument("--workers", type=int, default=1)

    ap.add_argument("--threshold", type=float, default=90.0)
    ap.add_argument("--use_docai", action="store_true", default=True)
    ap.add_argument("--no_docai", action="store_true", default=False)

    ap.add_argument("--keep_temp", action="store_true", default=False)
    ap.add_argument("--patch_in_validator", action="store_true", default=False)

    ap.add_argument("--docai_timeout_s", type=int, default=180)
    ap.add_argument("--docai_force_full_pdf", action="store_true", default=True)

    # Logs default relativ zum Root
    ap.add_argument("--log_dir", default="", help="Directory for logs (default: <root>/logs)")
    ap.add_argument("--log_csv", default="", help="CSV log file (default: <log_dir>/segments_pipeline.csv)")
    ap.add_argument("--log_jsonl", default="", help="JSONL log file (default: <log_dir>/segments_pipeline.jsonl)")

    ap.add_argument("--fail_fast", action="store_true", default=False)

    args = ap.parse_args()

    root = Path(args.root).resolve()

    pipeline_py = Path(args.pipeline).resolve() if args.pipeline else (here / "segments_pipeline.py")
    segmenter_py = Path(args.segmenter).resolve() if args.segmenter else (here / "segments.py")

    if not root.exists():
        raise SystemExit(f"Root not found: {root}")
    if not pipeline_py.exists():
        raise SystemExit(f"segments_pipeline.py not found: {pipeline_py}")
    if not segmenter_py.exists():
        raise SystemExit(f"segments.py not found: {segmenter_py}")

    log_dir = Path(args.log_dir).resolve() if args.log_dir else (root / "logs")
    log_dir.mkdir(parents=True, exist_ok=True)

    log_csv = Path(args.log_csv).resolve() if args.log_csv else (log_dir / "segments_pipeline.csv")
    log_jsonl = Path(args.log_jsonl).resolve() if args.log_jsonl else (log_dir / "segments_pipeline.jsonl")

    use_docai = bool(args.use_docai) and not bool(args.no_docai)

    jobs = _find_jobs(
        root=root,
        agenda_name=str(args.agenda_name),
        pdf_name=str(args.pdf_name).strip(),
        pdf_prefer=str(args.pdf_prefer).strip(),
        max_depth=int(args.max_depth),
    )

    if not jobs:
        print("No packages found (agenda.json + pdf).")
        return

    print(f"Found {len(jobs)} packages under {root}")
    ok = 0
    fail = 0

    if int(args.workers) <= 1:
        for i, job in enumerate(jobs, 1):
            print(f"[{i}/{len(jobs)}] {job.package_dir.name}")
            rc = _run_one(
                job,
                args.python,
                pipeline_py,
                segmenter_py,
                float(args.threshold),
                use_docai,
                bool(args.keep_temp),
                bool(args.patch_in_validator),
                int(args.docai_timeout_s),
                bool(args.docai_force_full_pdf),
                log_csv,
                log_jsonl,
            )
            if rc == 0:
                ok += 1
            else:
                fail += 1
                if args.fail_fast:
                    break
    else:
        with ThreadPoolExecutor(max_workers=int(args.workers)) as exe:
            futs = []
            for job in jobs:
                futs.append(exe.submit(
                    _run_one, job, args.python, pipeline_py, segmenter_py,
                    float(args.threshold), use_docai, bool(args.keep_temp),
                    bool(args.patch_in_validator), int(args.docai_timeout_s),
                    bool(args.docai_force_full_pdf), log_csv, log_jsonl
                ))
            for fut in as_completed(futs):
                rc = fut.result()
                if rc == 0:
                    ok += 1
                else:
                    fail += 1
                    if args.fail_fast:
                        break

    print("\nSummary")
    print(f"  OK:   {ok}")
    print(f"  FAIL: {fail}")
    print(f"  CSV:  {log_csv}")
    print(f"  JSONL:{log_jsonl}")


if __name__ == "__main__":
    main()