"""Prompt building and response parsing. Follows SPEC.md steps 1, 2 and 4.

Everything the model is told lives in the spec/ JSON and Markdown files.
This module only fills in the blanks.
"""

from __future__ import annotations

import copy
import html
import json
import re
from functools import lru_cache
from pathlib import Path

MODES = ("auto", "plain", "story")
LENGTHS = ("short", "medium", "long")


def spec_dir() -> Path:
    """Find the spec folder: bundled copy first, then the repo root."""
    here = Path(__file__).resolve().parent
    for candidate in (here / "spec", here.parent.parent / "spec"):
        if (candidate / "personas.json").exists():
            return candidate
    raise FileNotFoundError("Could not find the spec/ folder. Run scripts/sync-spec.sh.")


@lru_cache(maxsize=1)
def load_spec() -> dict:
    d = spec_dir()
    return {
        "personas": json.loads((d / "personas.json").read_text(encoding="utf-8")),
        "parts": json.loads((d / "prompt-parts.json").read_text(encoding="utf-8")),
        "tool": json.loads((d / "tool.json").read_text(encoding="utf-8")),
        "template": (d / "system-prompt.md").read_text(encoding="utf-8"),
    }


def persona_slugs() -> list[str]:
    return [p["slug"] for p in load_spec()["personas"]]


def _fill(text: str, values: dict) -> str:
    for key, value in values.items():
        text = text.replace("{{" + key + "}}", value)
    return text


def _is_english(language: str) -> bool:
    lang = (language or "").strip().lower()
    return lang == "" or lang == "english" or lang == "en" or lang.startswith("en-") or lang.startswith("en_")


def build_system_prompt(mode: str = "auto", persona: str = "auto", story_length: str = "medium", language: str = "") -> str:
    spec = load_spec()
    parts = spec["parts"]
    mode = mode if mode in MODES else "auto"
    story_length = story_length if story_length in LENGTHS else "medium"
    slugs = persona_slugs()
    persona = persona if persona in slugs else "auto"

    narrator_list = "\n".join(_fill(parts["narrator_line"], p) for p in spec["personas"])
    if persona == "auto":
        narrator_instruction = parts["narrator_instruction"]["auto"]
    else:
        narrator_instruction = _fill(parts["narrator_instruction"]["fixed"], {"persona": persona})

    language_instruction = "" if _is_english(language) else _fill(parts["language_instruction"], {"language": language.strip()})

    prompt = _fill(
        spec["template"],
        {
            "story_length": parts["story_length"][story_length],
            "mode_instruction": parts["mode_instruction"][mode],
            "narrator_list": narrator_list,
            "narrator_instruction": narrator_instruction,
            "language_instruction": language_instruction,
        },
    )
    prompt = re.sub(r"\n{3,}", "\n\n", prompt)
    return prompt.strip()


def build_tool() -> dict:
    tool = copy.deepcopy(load_spec()["tool"])
    tool["input_schema"]["properties"]["personas"]["items"]["enum"] = persona_slugs()
    return tool


CONTEXT_LABELS = (
    ("website", "Website"),
    ("about", "About the site"),
    ("file_name", "File name"),
    ("title", "Media title"),
    ("caption", "Caption"),
    ("description", "Description"),
    ("used_on", "Used on"),
)


def build_context_text(context: dict | None = None) -> str:
    context = context or {}
    lines = [load_spec()["parts"]["user_message_header"]]
    for key, label in CONTEXT_LABELS:
        value = str(context.get(key) or "").strip()
        if value:
            value = re.sub(r"\s+", " ", value)
            lines.append(f"- {label}: {value}")
    return "\n".join(lines)


def build_request(image_block: dict, context: dict | None, *, model: str, mode: str = "auto", persona: str = "auto",
                  story_length: str = "medium", language: str = "") -> dict:
    """Messages API request body (SPEC.md step 3)."""
    return {
        "model": model,
        "max_tokens": 1024,
        "system": build_system_prompt(mode, persona, story_length, language),
        "tools": [build_tool()],
        "tool_choice": {"type": "auto"},
        "messages": [
            {
                "role": "user",
                "content": [image_block, {"type": "text", "text": build_context_text(context)}],
            }
        ],
    }


_TAG_RE = re.compile(r"<[^>]+>")
_LEAD_RE = re.compile(r"^(an?\s+)?(image|picture|photo|photograph)\s+(of|showing)\s+", re.IGNORECASE)
_PAIRS = {'"': '"', "'": "'", "“": "”", "‘": "’"}


def clean(alt: str) -> str:
    alt = html.unescape(_TAG_RE.sub("", alt or ""))
    alt = re.sub(r"\s+", " ", alt).strip()
    # Remove quotes only when they wrap the whole text, so "Sign reading 'OPEN'" stays intact.
    while len(alt) >= 2 and _PAIRS.get(alt[0]) == alt[-1]:
        alt = alt[1:-1].strip()
    alt = _LEAD_RE.sub("", alt)
    if alt:
        alt = alt[0].upper() + alt[1:]
    return alt.strip()


class AltTextError(Exception):
    pass


def parse_response(message: dict, mode: str = "auto", persona: str = "auto") -> dict:
    """Pull the save_alt_text tool call out of a Messages API response (SPEC.md step 4)."""
    tool_input = None
    for block in message.get("content") or []:
        if block.get("type") == "tool_use" and block.get("name") == "save_alt_text":
            tool_input = block.get("input") or {}
            break
    if not tool_input:
        raise AltTextError("The model did not return any alt-text.")

    alt = clean(str(tool_input.get("alt_text") or ""))
    if not alt:
        raise AltTextError("The model did not return any alt-text.")

    out_mode = "story" if tool_input.get("mode") == "story" else "plain"
    if mode == "plain":
        out_mode = "plain"

    personas: list[str] = []
    if out_mode == "story":
        valid = persona_slugs()
        for slug in tool_input.get("personas") or []:
            if slug in valid and slug not in personas:
                personas.append(slug)
        personas = personas[:2]
        if persona in valid:
            personas = [persona]

    image_type = tool_input.get("image_type")
    if image_type not in load_spec()["parts"]["image_types"]:
        image_type = "other"

    return {"alt_text": alt, "mode": out_mode, "personas": personas, "image_type": image_type}
