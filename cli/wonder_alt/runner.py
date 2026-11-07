"""Describe images one at a time (in parallel) or through the Message Batches API."""

from __future__ import annotations

import csv
import json
import os
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

from .core import AltTextError, build_request, parse_response
from .http import APIError, api_request
from .images import ImageError, block_for
from .sources import ImageItem

COLUMNS = ["id", "source", "page_url", "alt_status", "current_alt", "alt_text", "mode", "personas", "image_type", "model", "error"]
DEFAULT_MODEL = os.environ.get("WONDER_ALT_MODEL", "claude-sonnet-5-5")


@dataclass
class Options:
    model: str = DEFAULT_MODEL
    mode: str = "auto"
    persona: str = "auto"
    story_length: str = "medium"
    language: str = ""
    website: str = ""
    about: str = ""
    send_urls: bool = False


def _context(item: ImageItem, opts: Options) -> dict:
    ctx = dict(item.context)
    ctx.setdefault("website", opts.website)
    ctx.setdefault("about", opts.about)
    return ctx


def request_for(item: ImageItem, opts: Options) -> dict:
    block = block_for(item.source, send_url=opts.send_urls)
    return build_request(block, _context(item, opts), model=opts.model, mode=opts.mode, persona=opts.persona,
                         story_length=opts.story_length, language=opts.language)


def is_fatal(err: Exception) -> bool:
    """Errors that would hit every image: bad key, no credit, wrong model."""
    if not isinstance(err, APIError):
        return False
    text = str(err).lower()
    return err.status in (401, 403, 404) or "api_key" in text or "credit balance" in text or "model:" in text


def describe(item: ImageItem, opts: Options) -> dict:
    """Describe one image. Returns a result row. Raises only for account-wide problems."""
    row = _row(item, opts)
    try:
        message = api_request("POST", "/v1/messages", request_for(item, opts))
        _fill_row(row, parse_response(message, opts.mode, opts.persona))
    except (APIError, AltTextError, ImageError, OSError, ValueError) as err:
        if is_fatal(err):
            raise
        row["error"] = str(err)
    return row


def _row(item: ImageItem, opts: Options) -> dict:
    return {
        "id": item.id, "source": item.source, "page_url": item.page_url, "alt_status": item.alt_status,
        "current_alt": item.current_alt, "alt_text": "", "mode": "", "personas": "", "image_type": "",
        "model": opts.model, "error": "",
    }


def _fill_row(row: dict, result: dict) -> None:
    row.update(alt_text=result["alt_text"], mode=result["mode"], personas="+".join(result["personas"]), image_type=result["image_type"])


# ---------- output ----------

class CsvWriter:
    """Append-only, thread-safe CSV writer that remembers what is already done."""

    def __init__(self, path: str):
        self.path = Path(path)
        self.lock = threading.Lock()
        self.done: set[str] = set()
        if self.path.exists() and self.path.stat().st_size:
            with self.path.open(newline="", encoding="utf-8") as fh:
                for row in csv.DictReader(fh):
                    if row.get("alt_text") and not row.get("error"):
                        self.done.add(row["id"])
        new = not self.path.exists() or not self.path.stat().st_size
        self.fh = self.path.open("a", newline="", encoding="utf-8")
        self.writer = csv.DictWriter(self.fh, fieldnames=COLUMNS, extrasaction="ignore")
        if new:
            self.writer.writeheader()
            self.fh.flush()

    def write(self, row: dict) -> None:
        with self.lock:
            self.writer.writerow(row)
            self.fh.flush()
            if row.get("alt_text") and not row.get("error"):
                self.done.add(row["id"])

    def close(self) -> None:
        self.fh.close()


def select(items: list[ImageItem], overwrite: bool, done: set[str], limit: int = 0) -> list[ImageItem]:
    todo = [i for i in items if i.id not in done and (overwrite or i.alt_status != "present")]
    return todo[:limit] if limit else todo


def run_live(items: list[ImageItem], opts: Options, out: str, *, overwrite=False, concurrency=4, limit=0) -> tuple[int, int]:
    writer = CsvWriter(out)
    todo = select(items, overwrite, writer.done, limit)
    skipped = len(items) - len(todo)
    print(f"{len(todo)} image(s) to describe ({skipped} skipped: already done or already have alt-text).", file=sys.stderr)
    ok = failed = 0
    try:
        with ThreadPoolExecutor(max_workers=max(1, concurrency)) as pool:
            futures = {pool.submit(describe, item, opts): item for item in todo}
            for n, fut in enumerate(as_completed(futures), start=1):
                try:
                    row = fut.result()
                except APIError:
                    for f in futures:
                        f.cancel()
                    raise
                writer.write(row)
                if row["error"]:
                    failed += 1
                    print(f"[{n}/{len(todo)}] FAILED {row['id']}: {row['error']}", file=sys.stderr)
                else:
                    ok += 1
                    voice = f" [{row['personas']}]" if row["personas"] else ""
                    print(f"[{n}/{len(todo)}] {row['mode']}{voice} {row['id']}", file=sys.stderr)
    finally:
        writer.close()
    return ok, failed


# ---------- Message Batches API (about half the cost, results within 24h, usually under 1h) ----------

BATCH_MAX_REQUESTS = 10_000
BATCH_MAX_BYTES = 200_000_000


def submit_batch(items: list[ImageItem], opts: Options, out: str, state_path: str, *, overwrite=False, limit=0) -> dict:
    done: set[str] = set()
    if Path(out).exists():
        w = CsvWriter(out)
        done = w.done
        w.close()
    todo = select(items, overwrite, done, limit)
    if not todo:
        print("Nothing to do.", file=sys.stderr)
        return {}

    state = {"out": out, "options": opts.__dict__, "batches": [], "items": {}}
    chunk: list[dict] = []
    size = 0

    def flush():
        nonlocal chunk, size
        if not chunk:
            return
        resp = api_request("POST", "/v1/messages/batches", {"requests": chunk})
        state["batches"].append(resp["id"])
        print(f"Submitted batch {resp['id']} with {len(chunk)} request(s).", file=sys.stderr)
        chunk, size = [], 0
        _save(state_path, state)

    for n, item in enumerate(todo, start=1):
        custom_id = f"img-{n:06d}"
        try:
            req = {"custom_id": custom_id, "params": request_for(item, opts)}
        except (ImageError, OSError, ValueError) as err:
            state["items"][custom_id] = {"item": item.__dict__, "prepare_error": str(err)}
            continue
        req_size = len(json.dumps(req))
        if chunk and (len(chunk) >= BATCH_MAX_REQUESTS or size + req_size > BATCH_MAX_BYTES):
            flush()
        chunk.append(req)
        size += req_size
        state["items"][custom_id] = {"item": item.__dict__}
        if n % 50 == 0:
            print(f"Prepared {n}/{len(todo)}...", file=sys.stderr)
    flush()
    _save(state_path, state)
    return state


def collect_batches(state_path: str, *, wait=True, poll_seconds=60) -> tuple[int, int]:
    state = json.loads(Path(state_path).read_text(encoding="utf-8"))
    opts = Options(**state["options"])
    writer = CsvWriter(state["out"])
    ok = failed = 0
    try:
        # Images that could not even be prepared.
        for info in state["items"].values():
            if info.get("prepare_error") and not info.get("written"):
                row = _row(ImageItem(**info["item"]), opts)
                row["error"] = info["prepare_error"]
                writer.write(row)
                info["written"] = True
                failed += 1

        for batch_id in state["batches"]:
            while True:
                batch = api_request("GET", f"/v1/messages/batches/{batch_id}")
                if batch.get("processing_status") == "ended":
                    break
                if not wait:
                    print(f"Batch {batch_id} is still {batch.get('processing_status')}. Run collect again later.", file=sys.stderr)
                    return ok, failed
                counts = batch.get("request_counts", {})
                print(f"Batch {batch_id}: {counts.get('succeeded', 0)} done, {counts.get('processing', 0)} processing. Checking again in {poll_seconds}s.", file=sys.stderr)
                time.sleep(poll_seconds)

            results_url = batch.get("results_url") or f"/v1/messages/batches/{batch_id}/results"
            parsed = urlparse(results_url)
            path = parsed.path + (f"?{parsed.query}" if parsed.query else "")
            for line in api_request("GET", path, raw=True, timeout=600).splitlines():
                if not line.strip():
                    continue
                res = json.loads(line)
                info = state["items"].get(res["custom_id"])
                if not info or info.get("written"):
                    continue
                item = ImageItem(**info["item"])
                row = _row(item, opts)
                result = res.get("result", {})
                if result.get("type") == "succeeded":
                    try:
                        _fill_row(row, parse_response(result["message"], opts.mode, opts.persona))
                    except AltTextError as err:
                        row["error"] = str(err)
                else:
                    err = result.get("error") or {}
                    inner = err.get("error", err) if isinstance(err, dict) else {}
                    message = inner.get("message", "") if isinstance(inner, dict) else str(err)
                    row["error"] = f"batch request {result.get('type', 'failed')}: {message}".rstrip(": ")
                writer.write(row)
                info["written"] = True
                if row["error"]:
                    failed += 1
                else:
                    ok += 1
            _save(state_path, state)
    finally:
        writer.close()
        _save(state_path, state)
    return ok, failed


def _save(path: str, state: dict) -> None:
    tmp = Path(path).with_suffix(".tmp")
    tmp.write_text(json.dumps(state, indent=1), encoding="utf-8")
    tmp.replace(path)
