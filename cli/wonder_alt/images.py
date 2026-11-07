"""Turn an image file or URL into a Claude image content block."""

from __future__ import annotations

import base64
import io
import mimetypes
from pathlib import Path

from .http import fetch_bytes

MAX_EDGE = 1568
MAX_BYTES = 4_500_000
SUPPORTED = {"image/jpeg", "image/png", "image/gif", "image/webp"}
EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}

try:  # Pillow is optional. Without it, images are sent as-is.
    from PIL import Image  # type: ignore

    HAVE_PIL = True
except ImportError:  # pragma: no cover
    HAVE_PIL = False


class ImageError(Exception):
    pass


def sniff_mime(data: bytes, fallback: str = "") -> str:
    if data[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return fallback


def _shrink(data: bytes, mime: str) -> tuple[bytes, str]:
    """Resize so the long edge is at most MAX_EDGE. Returns (bytes, mime)."""
    if not HAVE_PIL:
        return data, mime
    try:
        with Image.open(io.BytesIO(data)) as img:
            img.seek(0)
            too_big = max(img.size) > MAX_EDGE
            if not too_big and len(data) <= MAX_BYTES:
                return data, mime
            img = img.convert("RGBA") if img.mode in ("P", "LA", "RGBA") else img.convert("RGB")
            img.thumbnail((MAX_EDGE, MAX_EDGE))
            buf = io.BytesIO()
            if img.mode == "RGBA" and mime in ("image/png", "image/gif"):
                img.save(buf, format="PNG", optimize=True)
                return buf.getvalue(), "image/png"
            img.convert("RGB").save(buf, format="JPEG", quality=85)
            return buf.getvalue(), "image/jpeg"
    except Exception:  # Unreadable by Pillow: send the original and let the API decide.
        return data, mime


def block_from_bytes(data: bytes, mime_hint: str = "") -> dict:
    mime = sniff_mime(data, mime_hint)
    if mime not in SUPPORTED:
        raise ImageError(f"Unsupported image type: {mime or 'unknown'}")
    data, mime = _shrink(data, mime)
    if len(data) > MAX_BYTES:
        raise ImageError("Image is larger than 4.5 MB. Install Pillow (pip install pillow) so it can be resized.")
    return {
        "type": "image",
        "source": {"type": "base64", "media_type": mime, "data": base64.b64encode(data).decode("ascii")},
    }


def block_from_path(path: str | Path) -> dict:
    path = Path(path)
    data = path.read_bytes()
    return block_from_bytes(data, mimetypes.guess_type(path.name)[0] or "")


def block_from_url(url: str, *, send_url: bool = False) -> dict:
    """Download and embed the image, or (send_url=True) let the API fetch the public URL itself."""
    if send_url:
        return {"type": "image", "source": {"type": "url", "url": url}}
    data, content_type = fetch_bytes(url)
    return block_from_bytes(data, (content_type or "").split(";")[0].strip())


def block_for(source: str, *, send_url: bool = False) -> dict:
    if source.startswith(("http://", "https://")):
        return block_from_url(source, send_url=send_url)
    return block_from_path(source)
