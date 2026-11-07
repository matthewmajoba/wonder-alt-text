"""Check the WordPress plugin and the Python CLI build identical prompts and clean text the same way."""

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(root / "cli"))
sys.path.insert(0, str(root / "cli" / "tests"))

from wonder_alt import core  # noqa: E402

HARNESS = root / "wordpress" / "tests" / "harness.php"


def php(*args, env=None) -> str:
    return subprocess.run(["php", str(HARNESS), *args], check=True, capture_output=True, text=True, env=env).stdout


failures = 0

# 1. Every prompt combination.
prompts = json.loads(php("prompts"))
tool = prompts.pop("__tool")
for key, php_prompt in prompts.items():
    mode, persona, length, locale = key.split("|")
    py_prompt = core.build_system_prompt(mode, persona, length, locale)
    if php_prompt != py_prompt:
        failures += 1
        print(f"PROMPT MISMATCH {key}")
if tool != core.build_tool():
    failures += 1
    print("TOOL MISMATCH")
print(f"prompts compared: {len(prompts)}")

# 2. Text cleanup.
cases = [
    ' "Image of a <b>red</b> kayak &amp; paddle" ',
    "photograph showing fog over pines",
    "Café sign reading ‘OPEN’",
    "“‘Quoted twice’”",
    "an image of   snow\n on cedar",
    "élan in the morning",
    "Picture of",
]
for c in cases:
    a, b = php("clean", c), core.clean(c)
    if a != b:
        failures += 1
        print(f"CLEAN MISMATCH {c!r}: php={a!r} py={b!r}")
print(f"clean cases compared: {len(cases)}")

# 3. A real request from PHP against the mock API.
import mock_api  # noqa: E402

server = mock_api.serve()
try:
    env = dict(os.environ, WONDER_ALT_API_URL=f"http://127.0.0.1:{server.server_port}")
    with tempfile.TemporaryDirectory() as d:
        img = Path(d) / "lake-dawn.jpg"
        img.write_bytes(bytes.fromhex("ffd8ffe000104a46494600010100000100010000ffd9"))
        out = json.loads(php("generate", str(img), "image/jpeg", "naturalist", env=env))
        expect = {"mode": "story", "personas": ["naturalist"], "image_type": "scene"}
        if any(out.get(k) != v for k, v in expect.items()) or not out.get("alt", "").startswith("Morning light"):
            failures += 1
            print(f"GENERATE MISMATCH: {out}")
        sent = mock_api.LOG[-1]
        py_text = core.build_context_text({"website": "Test Lodge", "file_name": "lake-dawn.jpg", "title": "Lake at dawn"})
        if sent["text"] != py_text:
            failures += 1
            print(f"CONTEXT MISMATCH:\n{sent['text']}\n---\n{py_text}")
        print("php generate request: ok" if not failures else "php generate request: see above")
finally:
    server.shutdown()
    server.server_close()

if failures:
    print(f"{failures} parity failure(s)")
    sys.exit(1)
print("PHP and Python agree.")
