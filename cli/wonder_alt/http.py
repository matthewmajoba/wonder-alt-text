"""Tiny HTTP helpers on the standard library, so the tool has no required dependencies."""

from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.request

from . import __version__

USER_AGENT = os.environ.get("WONDER_ALT_USER_AGENT", f"WonderAltText/{__version__}")
API_VERSION = "2023-06-01"


class APIError(Exception):
    def __init__(self, message: str, status: int = 0):
        super().__init__(message)
        self.status = status


def fetch_bytes(url: str, timeout: int = 30, max_bytes: int = 40_000_000) -> tuple[bytes, str]:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310 (user-supplied URLs are the point)
        data = resp.read(max_bytes + 1)
        if len(data) > max_bytes:
            raise ValueError(f"{url} is larger than {max_bytes // 1_000_000} MB")
        return data, resp.headers.get("Content-Type", "")


def fetch_text(url: str, timeout: int = 30) -> str:
    data, content_type = fetch_bytes(url, timeout=timeout, max_bytes=10_000_000)
    charset = "utf-8"
    if "charset=" in content_type:
        charset = content_type.split("charset=")[-1].split(";")[0].strip() or "utf-8"
    return data.decode(charset, errors="replace")


def api_key() -> str:
    key = os.environ.get("ANTHROPIC_API_KEY", "").strip()
    if not key:
        raise APIError("Set the ANTHROPIC_API_KEY environment variable. Get a key at https://console.anthropic.com.")
    return key


def api_request(method: str, path: str, body: dict | None = None, *, timeout: int = 120, retries: int = 3, raw: bool = False):
    """Call the Claude API. Retries on 429, 529 and 5xx. Returns parsed JSON (or text if raw=True)."""
    url = os.environ.get("WONDER_ALT_API_URL", "https://api.anthropic.com").rstrip("/") + path
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {
        "x-api-key": api_key(),
        "anthropic-version": API_VERSION,
        "content-type": "application/json",
        "user-agent": USER_AGENT,
    }
    attempt = 0
    while True:
        attempt += 1
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310
                text = resp.read().decode("utf-8")
                return text if raw else json.loads(text)
        except urllib.error.HTTPError as err:
            status = err.code
            detail = err.read().decode("utf-8", errors="replace")
            if attempt <= retries and (status in (429, 529) or status >= 500):
                wait = err.headers.get("retry-after")
                time.sleep(min(30, float(wait)) if wait and wait.replace(".", "", 1).isdigit() else 2 ** attempt)
                continue
            try:
                message = json.loads(detail)["error"]["message"]
            except Exception:
                message = detail[:300] or f"HTTP {status}"
            raise APIError(f"Claude API error ({status}): {message}", status) from None
        except urllib.error.URLError as err:
            if attempt <= 2:
                time.sleep(attempt)
                continue
            raise APIError(f"Could not reach the Claude API: {err.reason}") from None
