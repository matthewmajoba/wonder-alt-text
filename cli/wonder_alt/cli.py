"""Command-line interface.

    wonder-alt describe photo.jpg
    wonder-alt audit https://example.com/sitemap.xml --out audit.csv
    wonder-alt run https://example.com/sitemap.xml --out alt-text.csv
    wonder-alt run ./images --out alt-text.csv --batch
    wonder-alt collect alt-text.csv.batch.json
    wonder-alt personas
    wonder-alt prompt --mode story --persona painter
"""

from __future__ import annotations

import argparse
import csv
import json
import sys

from . import __version__
from .http import api_key
from .core import LENGTHS, MODES, build_system_prompt, load_spec, persona_slugs
from .runner import COLUMNS, DEFAULT_MODEL, Options, collect_batches, describe, run_live, submit_batch
from .sources import ImageItem, discover


def _style_args(p: argparse.ArgumentParser) -> None:
    g = p.add_argument_group("style")
    g.add_argument("--mode", choices=MODES, default="auto", help="plain, story, or auto (default) to let the model decide per image")
    g.add_argument("--persona", choices=["auto", *persona_slugs()], default="auto", help="narrator for story mode (default: auto picks or blends)")
    g.add_argument("--length", choices=LENGTHS, default="medium", help="story length: 1, 2 or 3 sentences (default: medium)")
    g.add_argument("--language", default="", help="write alt-text in this language, e.g. Spanish (default: English)")
    g.add_argument("--website", default="", help="site name, sent as context")
    g.add_argument("--about", default="", help="one line about the site, e.g. 'A family lodge on Mount Hood'. Helps name places correctly")
    g.add_argument("--model", default=DEFAULT_MODEL, help=f"Claude model ID (default: {DEFAULT_MODEL})")
    g.add_argument("--send-urls", action="store_true", help="let the API fetch public image URLs itself instead of downloading them first")


def _opts(a) -> Options:
    return Options(model=a.model, mode=a.mode, persona=a.persona, story_length=a.length, language=a.language,
                   website=a.website, about=a.about, send_urls=a.send_urls)


def cmd_describe(a) -> int:
    items = discover(a.image)
    if len(items) != 1:
        print("describe takes one image file or image URL. Use 'run' for folders, CSVs and sites.", file=sys.stderr)
        return 2
    item = items[0]
    if a.title:
        item.context["title"] = a.title
    row = describe(item, _opts(a))
    if a.json:
        print(json.dumps(row, indent=2))
    elif row["error"]:
        print(row["error"], file=sys.stderr)
    else:
        voice = f" · {row['personas']}" if row["personas"] else ""
        print(f"[{row['mode']}{voice}]", file=sys.stderr)
        print(row["alt_text"])
    return 1 if row["error"] else 0


def cmd_audit(a) -> int:
    items = discover(a.source, max_pages=a.max_pages)
    counts = {"missing": 0, "empty": 0, "present": 0}
    out = open(a.out, "w", newline="", encoding="utf-8") if a.out else sys.stdout
    try:
        w = csv.DictWriter(out, fieldnames=["id", "source", "page_url", "alt_status", "current_alt"])
        w.writeheader()
        for i in items:
            counts[i.alt_status] = counts.get(i.alt_status, 0) + 1
            w.writerow({"id": i.id, "source": i.source, "page_url": i.page_url, "alt_status": i.alt_status, "current_alt": i.current_alt})
    finally:
        if a.out:
            out.close()
    print(f"{len(items)} image(s): {counts['missing']} missing alt, {counts['empty']} empty alt, {counts['present']} with alt.", file=sys.stderr)
    return 0


def cmd_run(a) -> int:
    api_key()  # fail fast before scanning a whole site
    items = discover(a.source, max_pages=a.max_pages)
    if a.skip_empty:
        # alt="" is sometimes meant to mark an image as decorative.
        items = [i for i in items if i.alt_status != "empty"]
    opts = _opts(a)
    if a.batch:
        state_path = a.out + ".batch.json"
        state = submit_batch(items, opts, a.out, state_path, overwrite=a.overwrite, limit=a.limit)
        if not state:
            return 0
        print(f"Saved progress to {state_path}.", file=sys.stderr)
        if a.no_wait:
            print(f"Run 'wonder-alt collect {state_path}' later to download the results.", file=sys.stderr)
            return 0
        ok, failed = collect_batches(state_path)
    else:
        ok, failed = run_live(items, opts, a.out, overwrite=a.overwrite, concurrency=a.concurrency, limit=a.limit)
    print(f"Done: {ok} written, {failed} failed. Results in {a.out}", file=sys.stderr)
    return 1 if failed and not ok else 0


def cmd_collect(a) -> int:
    ok, failed = collect_batches(a.state, wait=not a.no_wait, poll_seconds=a.poll)
    print(f"Collected: {ok} written, {failed} failed.", file=sys.stderr)
    return 0


def cmd_personas(a) -> int:
    for p in load_spec()["personas"]:
        print(f"{p['slug']:<12} {p['name']}: {p['tagline']}")
    return 0


def cmd_prompt(a) -> int:
    print(build_system_prompt(a.mode, a.persona, a.length, a.language))
    return 0


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="wonder-alt", description="Alt-text that takes people somewhere.")
    p.add_argument("--version", action="version", version=f"wonder-alt {__version__}")
    sub = p.add_subparsers(dest="command", required=True)

    d = sub.add_parser("describe", help="describe one image file or URL and print the alt-text")
    d.add_argument("image")
    d.add_argument("--title", default="", help="optional title or product name for context")
    d.add_argument("--json", action="store_true", help="print the full result as JSON")
    _style_args(d)
    d.set_defaults(func=cmd_describe)

    au = sub.add_parser("audit", help="list images and their alt-text status (no API calls, free)")
    au.add_argument("source", help="folder, CSV, page URL or sitemap URL")
    au.add_argument("--out", default="", help="CSV file to write (default: print)")
    au.add_argument("--max-pages", type=int, default=500)
    au.set_defaults(func=cmd_audit)

    r = sub.add_parser("run", help="write alt-text for every image that needs it")
    r.add_argument("source", help="image file, folder, CSV, page URL or sitemap URL")
    r.add_argument("--out", required=True, help="results CSV. Re-running with the same file skips finished images")
    r.add_argument("--overwrite", action="store_true", help="also rewrite images that already have alt-text")
    r.add_argument("--skip-empty", action="store_true", help='leave web images that have alt="" alone (some sites use it for decorative images)')
    r.add_argument("--limit", type=int, default=0, help="stop after this many images (good for a test run)")
    r.add_argument("--concurrency", type=int, default=4, help="parallel requests in live mode (default: 4)")
    r.add_argument("--batch", action="store_true", help="use the Message Batches API: about half the cost, slower")
    r.add_argument("--no-wait", action="store_true", help="with --batch: submit and exit, collect later")
    r.add_argument("--max-pages", type=int, default=500, help="max pages to scan from a sitemap (default: 500)")
    _style_args(r)
    r.set_defaults(func=cmd_run)

    c = sub.add_parser("collect", help="download results of a --batch run")
    c.add_argument("state", help="the .batch.json file written by run --batch")
    c.add_argument("--no-wait", action="store_true", help="exit if batches are still processing")
    c.add_argument("--poll", type=int, default=60, help="seconds between status checks")
    c.set_defaults(func=cmd_collect)

    pe = sub.add_parser("personas", help="list the narrators")
    pe.set_defaults(func=cmd_personas)

    pr = sub.add_parser("prompt", help="print the system prompt (handy for review or other tools)")
    pr.add_argument("--mode", choices=MODES, default="auto")
    pr.add_argument("--persona", choices=["auto", *persona_slugs()], default="auto")
    pr.add_argument("--length", choices=LENGTHS, default="medium")
    pr.add_argument("--language", default="")
    pr.set_defaults(func=cmd_prompt)
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    try:
        return args.func(args)
    except KeyboardInterrupt:
        print("\nStopped. Re-run the same command to pick up where you left off.", file=sys.stderr)
        return 130
    except Exception as err:  # friendly one-line errors
        print(f"error: {err}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
