"""A fake Claude API for tests and offline demos.

    python tests/mock_api.py 8765
    WONDER_ALT_API_URL=http://127.0.0.1:8765 ANTHROPIC_API_KEY=test wonder-alt run ./images --out out.csv

It checks each request has the shape SPEC.md describes, then answers with a
save_alt_text tool call. Image names containing "lake", "trail" or "mountain"
get story mode; anything else gets plain mode.
"""

from __future__ import annotations

import base64
import json
import re
import sys
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BATCHES: dict[str, dict] = {}
LOG: list[dict] = []


def check_request(body: dict) -> str | None:
    if not body.get("model"):
        return "missing model"
    if body.get("tool_choice") != {"type": "auto"}:
        return "tool_choice must be auto (current models reject forced tool use)"
    tools = body.get("tools") or []
    if not tools or tools[0].get("name") != "save_alt_text":
        return "missing save_alt_text tool"
    system = body.get("system", "")
    if "{{" in system or "NARRATORS" not in system:
        return "system prompt not rendered"
    content = body["messages"][0]["content"]
    if content[0]["type"] != "image" or content[1]["type"] != "text":
        return "content must be [image, text]"
    src = content[0]["source"]
    if src["type"] == "base64":
        raw = base64.b64decode(src["data"])
        if src["media_type"] not in ("image/jpeg", "image/png", "image/gif", "image/webp"):
            return "bad media_type"
        if not raw:
            return "empty image"
    elif src["type"] != "url":
        return "bad image source"
    return None


def answer(body: dict) -> dict:
    text = body["messages"][0]["content"][1]["text"]
    name = (re.search(r"- File name: (.+)", text) or [None, "image"])[1]
    story = any(w in name.lower() for w in ("lake", "trail", "mountain"))
    fixed = re.search(r"narrate as (\w+)\.", body["system"])
    if story:
        personas = [fixed.group(1)] if fixed else ["painter", "naturalist"]
        alt = f'"Image of morning light spilling across {name}, the water so still it holds the whole sky."'
    else:
        personas, alt = [], f"Photo of {name} on a white background"
    LOG.append({"file": name, "system": body["system"], "text": text, "image": body["messages"][0]["content"][0]["source"]})
    return {
        "id": "msg_" + uuid.uuid4().hex[:12],
        "type": "message",
        "role": "assistant",
        "model": body["model"],
        "stop_reason": "tool_use",
        "content": [
            {
                "type": "tool_use",
                "id": "toolu_1",
                "name": "save_alt_text",
                "input": {"image_type": "scene" if story else "product", "mode": "story" if story else "plain",
                          "personas": personas, "alt_text": alt},
            }
        ],
    }


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):  # quiet
        pass

    def _send(self, code: int, payload, raw: bool = False):
        data = payload.encode() if raw else json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("content-type", "application/x-jsonl" if raw else "application/json")
        self.send_header("content-length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _auth(self) -> bool:
        if not self.headers.get("x-api-key") or self.headers.get("anthropic-version") != "2023-06-01":
            self._send(401, {"type": "error", "error": {"type": "authentication_error", "message": "missing headers"}})
            return False
        return True

    def do_POST(self):
        if not self._auth():
            return
        body = json.loads(self.rfile.read(int(self.headers["content-length"])))
        if self.path == "/v1/messages":
            problem = check_request(body)
            if problem:
                return self._send(400, {"type": "error", "error": {"type": "invalid_request_error", "message": problem}})
            return self._send(200, answer(body))
        if self.path == "/v1/messages/batches":
            for r in body["requests"]:
                if not re.fullmatch(r"[a-zA-Z0-9_-]{1,64}", r["custom_id"]):
                    return self._send(400, {"type": "error", "error": {"message": "bad custom_id"}})
                problem = check_request(r["params"])
                if problem:
                    return self._send(400, {"type": "error", "error": {"message": problem}})
            bid = "msgbatch_" + uuid.uuid4().hex[:10]
            BATCHES[bid] = {"requests": body["requests"], "polls": 0}
            return self._send(200, {"id": bid, "type": "message_batch", "processing_status": "in_progress",
                                    "request_counts": {"processing": len(body["requests"])}})
        self._send(404, {"error": {"message": "not found"}})

    def do_GET(self):
        if not self._auth():
            return
        m = re.fullmatch(r"/v1/messages/batches/(\w+)(/results)?", self.path)
        if not m or m.group(1) not in BATCHES:
            return self._send(404, {"error": {"message": "not found"}})
        b = BATCHES[m.group(1)]
        if m.group(2):
            lines = [json.dumps({"custom_id": r["custom_id"], "result": {"type": "succeeded", "message": answer(r["params"])}})
                     for r in b["requests"]]
            return self._send(200, "\n".join(lines) + "\n", raw=True)
        b["polls"] += 1
        ended = b["polls"] >= 2
        host = self.headers.get("host")
        return self._send(200, {
            "id": m.group(1), "processing_status": "ended" if ended else "in_progress",
            "request_counts": {"succeeded": len(b["requests"]) if ended else 0, "processing": 0 if ended else len(b["requests"])},
            "results_url": f"http://{host}/v1/messages/batches/{m.group(1)}/results" if ended else None,
        })


def serve(port: int = 0) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


if __name__ == "__main__":
    s = serve(int(sys.argv[1]) if len(sys.argv) > 1 else 8765)
    print(f"Mock Claude API on http://127.0.0.1:{s.server_port}")
    threading.Event().wait()
