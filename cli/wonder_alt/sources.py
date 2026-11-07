"""Find images to describe: a file, a folder, a CSV, a web page, or a whole site via its sitemap."""

from __future__ import annotations

import csv
import sys
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urldefrag, urlparse

from .http import fetch_text
from .images import EXTENSIONS


@dataclass
class ImageItem:
    id: str
    source: str
    page_url: str = ""
    alt_status: str = "missing"  # missing | empty | present
    current_alt: str = ""
    context: dict = field(default_factory=dict)


# ---------- local files ----------

def from_path(path: str) -> list[ImageItem]:
    p = Path(path)
    if p.is_file():
        files, root = [p], p.parent
    else:
        root = p
        files = sorted(f for f in p.rglob("*") if f.is_file() and f.suffix.lower() in EXTENSIONS)
    items = []
    for f in files:
        rel = f.relative_to(root).as_posix()
        items.append(ImageItem(id=rel, source=str(f), context={"file_name": f.name, "title": f.stem.replace("-", " ").replace("_", " ")}))
    return items


# ---------- CSV ----------

def from_csv(path: str) -> list[ImageItem]:
    """Columns (case-insensitive): url or path (required), id, page_url, alt, title, caption, description, used_on."""
    items = []
    with open(path, newline="", encoding="utf-8-sig") as fh:
        for n, row in enumerate(csv.DictReader(fh), start=1):
            row = {(k or "").strip().lower(): (v or "").strip() for k, v in row.items()}
            source = row.get("url") or row.get("image_url") or row.get("path") or row.get("source")
            if not source:
                print(f"warning: CSV row {n} has no url/path column, skipped", file=sys.stderr)
                continue
            if "alt" in row:
                alt = row["alt"]
                status = "present" if alt else "missing"
            else:
                alt, status = "", "missing"
            name = Path(urlparse(source).path).name
            items.append(
                ImageItem(
                    id=row.get("id") or source,
                    source=source,
                    page_url=row.get("page_url", ""),
                    alt_status=status,
                    current_alt=alt,
                    context={
                        "file_name": name,
                        "title": row.get("title", ""),
                        "caption": row.get("caption", ""),
                        "description": row.get("description", ""),
                        "used_on": row.get("used_on", ""),
                    },
                )
            )
    return items


# ---------- web pages ----------

class _ImgParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.images: list[dict] = []
        self.title = ""
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "img":
            src = a.get("src") or ""
            if not src or src.startswith("data:"):  # lazy-loading placeholders
                src = a.get("data-src") or a.get("data-lazy-src") or src
            if not src or src.startswith("data:"):
                srcset = a.get("srcset") or a.get("data-srcset") or ""
                src = srcset.split(",")[-1].strip().split(" ")[0] if srcset else ""
            if src and not src.startswith("data:"):
                hidden = a.get("aria-hidden") == "true" or a.get("role") in ("presentation", "none")
                self.images.append({"src": src, "alt": a.get("alt"), "hidden": hidden})
        elif tag == "title":
            self._in_title = True

    def handle_endtag(self, tag):
        if tag == "title":
            self._in_title = False

    def handle_data(self, data):
        if self._in_title:
            self.title += data


def _page_images(page_url: str) -> tuple[str, list[dict]]:
    parser = _ImgParser()
    parser.feed(fetch_text(page_url))
    return parser.title.strip(), parser.images


def _sitemap_urls(url: str, limit: int, seen: set | None = None) -> list[str]:
    seen = seen if seen is not None else set()
    if url in seen:
        return []
    seen.add(url)
    root = ET.fromstring(fetch_text(url).encode("utf-8"))
    tag = root.tag.split("}")[-1]
    locs = [el.text.strip() for el in root.iter() if el.tag.split("}")[-1] == "loc" and el.text]
    if tag == "sitemapindex":
        pages: list[str] = []
        for child in locs:
            if len(pages) >= limit:
                break
            try:
                pages.extend(_sitemap_urls(child, limit - len(pages), seen))
            except Exception as err:  # keep going if one child sitemap fails
                print(f"warning: could not read sitemap {child}: {err}", file=sys.stderr)
        return pages[:limit]
    return locs[:limit]


def _looks_like_sitemap(url: str) -> bool:
    path = urlparse(url).path.lower()
    return path.endswith(".xml") or "sitemap" in path


def from_site(url: str, max_pages: int = 500, workers: int = 4) -> list[ImageItem]:
    """Scan a page, or every page in a sitemap, for <img> tags."""
    pages = _sitemap_urls(url, max_pages) if _looks_like_sitemap(url) else [url]
    print(f"Scanning {len(pages)} page(s)...", file=sys.stderr)

    def scan(page):
        try:
            return page, *_page_images(page)
        except Exception as err:
            print(f"warning: could not read {page}: {err}", file=sys.stderr)
            return page, "", []

    items: dict[str, ImageItem] = {}
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for page, title, imgs in pool.map(scan, pages):
            for img in imgs:
                src = urldefrag(urljoin(page, img["src"]))[0]
                if not src.startswith(("http://", "https://")):
                    continue
                if Path(urlparse(src).path).suffix.lower() == ".svg":
                    continue
                alt = img["alt"]
                status = "missing" if alt is None else ("empty" if alt.strip() == "" else "present")
                existing = items.get(src)
                # The same image can appear on many pages. Keep the worst status so a gap is never hidden.
                if existing and not (existing.alt_status == "present" and status != "present"):
                    continue
                items[src] = ImageItem(
                    id=src,
                    source=src,
                    page_url=page,
                    alt_status=status,
                    current_alt=(alt or "").strip(),
                    context={"file_name": Path(urlparse(src).path).name, "used_on": f"{title} ({page})" if title else page},
                )
    return list(items.values())


def discover(source: str, max_pages: int = 500) -> list[ImageItem]:
    if source.startswith(("http://", "https://")):
        if Path(urlparse(source).path).suffix.lower() in EXTENSIONS:
            name = Path(urlparse(source).path).name
            return [ImageItem(id=source, source=source, context={"file_name": name})]
        return from_site(source, max_pages=max_pages)
    if source.lower().endswith(".csv"):
        return from_csv(source)
    if not Path(source).exists():
        raise FileNotFoundError(source)
    return from_path(source)
