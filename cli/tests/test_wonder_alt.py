"""Run with:  cd cli && python -m unittest discover tests -v"""

from __future__ import annotations

import base64
import csv
import io
import json
import os
import sys
import tempfile
import threading
import unittest
from contextlib import redirect_stderr
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))

import mock_api  # noqa: E402
from wonder_alt import core, images, sources  # noqa: E402
from wonder_alt.cli import main  # noqa: E402

try:
    from PIL import Image
except ImportError:  # pragma: no cover
    Image = None


def make_image(path: Path, size=(40, 30), color=(30, 120, 200), fmt="JPEG"):
    if Image is None:
        # 1x1 PNG
        path.write_bytes(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="))
        return
    Image.new("RGB", size, color).save(path, fmt)


def run_cli(*args) -> tuple[int, str]:
    err = io.StringIO()
    with redirect_stderr(err):
        code = main(list(args))
    return code, err.getvalue()


def read_csv(path) -> list[dict]:
    with open(path, newline="", encoding="utf-8") as fh:
        return list(csv.DictReader(fh))


class PromptTests(unittest.TestCase):
    def test_all_placeholders_filled(self):
        for mode in core.MODES:
            for persona in ["auto", *core.persona_slugs()]:
                for length in core.LENGTHS:
                    p = core.build_system_prompt(mode, persona, length, "")
                    self.assertNotIn("{{", p)
                    self.assertNotIn("\n\n\n", p)
                    self.assertTrue(p.endswith("Call the save_alt_text tool with your answer."))

    def test_fixed_persona_and_language(self):
        p = core.build_system_prompt("story", "painter", "short", "Spanish")
        self.assertIn("narrate as painter.", p)
        self.assertIn("Write one sentence that carry", p)
        self.assertIn("Write the alt-text in this language: Spanish.", p)
        self.assertNotIn("in this language", core.build_system_prompt(language="en-US"))

    def test_no_real_names_in_prompt(self):
        p = core.build_system_prompt().lower()
        for name in ("sagan", "rogers", "bob ross", "carson", "watts"):
            self.assertNotIn(name, p)

    def test_tool_enum_matches_personas(self):
        tool = core.build_tool()
        self.assertEqual(tool["input_schema"]["properties"]["personas"]["items"]["enum"], core.persona_slugs())

    def test_context_text(self):
        t = core.build_context_text({"website": "Lodge", "title": "  Lake\n view ", "caption": ""})
        self.assertIn("- Website: Lodge", t)
        self.assertIn("- Media title: Lake view", t)
        self.assertNotIn("Caption", t)


class ParseTests(unittest.TestCase):
    def msg(self, **inp):
        return {"content": [{"type": "text", "text": "hi"}, {"type": "tool_use", "name": "save_alt_text", "input": inp}]}

    def test_clean(self):
        self.assertEqual(core.clean(' "Image of a <b>red</b> kayak &amp; paddle" '), "A red kayak & paddle")
        self.assertEqual(core.clean("photograph showing fog over pines"), "Fog over pines")
        self.assertEqual(core.clean("Café sign reading ‘OPEN’"), "Café sign reading ‘OPEN’")
        self.assertEqual(core.clean("“‘Quoted twice’”"), "Quoted twice")

    def test_story(self):
        r = core.parse_response(self.msg(mode="story", personas=["painter", "bogus", "painter", "naturalist", "stargazer"], alt_text="Gold light on the lake.", image_type="nature"))
        self.assertEqual(r, {"alt_text": "Gold light on the lake.", "mode": "story", "personas": ["painter", "naturalist"], "image_type": "nature"})

    def test_plain_forced(self):
        r = core.parse_response(self.msg(mode="story", personas=["painter"], alt_text="x", image_type="weird"), mode="plain")
        self.assertEqual((r["mode"], r["personas"], r["image_type"]), ("plain", [], "other"))

    def test_fixed_persona_reported(self):
        r = core.parse_response(self.msg(mode="story", personas=["painter"], alt_text="x", image_type="scene"), persona="neighbor")
        self.assertEqual(r["personas"], ["neighbor"])

    def test_missing(self):
        with self.assertRaises(core.AltTextError):
            core.parse_response({"content": [{"type": "text", "text": "no tool"}]})
        with self.assertRaises(core.AltTextError):
            core.parse_response(self.msg(alt_text='  ""  '))


class ImageTests(unittest.TestCase):
    @unittest.skipIf(Image is None, "Pillow not installed")
    def test_resize_large(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "big.png"
            make_image(p, size=(4000, 1000), fmt="PNG")
            block = images.block_from_path(p)
            data = base64.b64decode(block["source"]["data"])
            with Image.open(io.BytesIO(data)) as img:
                self.assertEqual(max(img.size), images.MAX_EDGE)

    def test_unsupported(self):
        with self.assertRaises(images.ImageError):
            images.block_from_bytes(b"<svg></svg>", "image/svg+xml")

    def test_url_passthrough(self):
        self.assertEqual(images.block_from_url("https://x.test/a.jpg", send_url=True)["source"], {"type": "url", "url": "https://x.test/a.jpg"})


class SiteFixture:
    """Serve a tiny website with a sitemap index."""

    def __init__(self):
        self.dir = tempfile.TemporaryDirectory()
        root = Path(self.dir.name)
        (root / "img").mkdir()
        for name in ("lake-sunrise.jpg", "kayak.jpg", "trail.jpg", "logo.jpg"):
            make_image(root / "img" / name)
        (root / "index.html").write_text(
            '<html><head><title>Home</title></head><body>'
            '<img src="/img/lake-sunrise.jpg">'
            '<img src="img/kayak.jpg" alt="">'
            '<img src="/img/logo.jpg" alt="Lodge logo">'
            '<img src="data:image/gif;base64,R0lGOD" data-src="/img/trail.jpg">'
            '<img src="/icon.svg">'
            "</body></html>")
        (root / "about.html").write_text('<title>About</title><img src="/img/kayak.jpg" alt="Red kayak">')
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), partial(QuietHandler, directory=str(root)))
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.base = f"http://127.0.0.1:{self.server.server_port}"
        (root / "sitemap-pages.xml").write_text(
            '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            f"<url><loc>{self.base}/index.html</loc></url><url><loc>{self.base}/about.html</loc></url></urlset>")
        (root / "sitemap.xml").write_text(
            '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            f"<sitemap><loc>{self.base}/sitemap-pages.xml</loc></sitemap></sitemapindex>")

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.dir.cleanup()


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


class SourceTests(unittest.TestCase):
    def setUp(self):
        self.site = SiteFixture()

    def tearDown(self):
        self.site.close()

    def test_sitemap_scan(self):
        items = {Path(i.id).name: i for i in sources.discover(self.site.base + "/sitemap.xml")}
        self.assertEqual(set(items), {"lake-sunrise.jpg", "kayak.jpg", "logo.jpg", "trail.jpg"})
        self.assertEqual(items["lake-sunrise.jpg"].alt_status, "missing")
        self.assertEqual(items["trail.jpg"].alt_status, "missing")  # lazy-loaded data-src
        self.assertEqual(items["logo.jpg"].alt_status, "present")
        # kayak has alt on /about but alt="" on the home page: the gap must not be hidden
        self.assertEqual(items["kayak.jpg"].alt_status, "empty")
        self.assertIn("Home", items["lake-sunrise.jpg"].context["used_on"])

    def test_csv(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "in.csv"
            p.write_text("ID,URL,Alt,Title\n7,https://x.test/a.jpg,,Kayak\n8,https://x.test/b.jpg,Already,\n,,,\n")
            with redirect_stderr(io.StringIO()):
                items = sources.discover(str(p))
            self.assertEqual([(i.id, i.alt_status) for i in items], [("7", "missing"), ("8", "present")])
            self.assertEqual(items[0].context["title"], "Kayak")


class EndToEndTests(unittest.TestCase):
    def setUp(self):
        self.api = mock_api.serve()
        self.env = {"WONDER_ALT_API_URL": f"http://127.0.0.1:{self.api.server_port}", "ANTHROPIC_API_KEY": "test-key"}
        self.old = {k: os.environ.get(k) for k in self.env}
        os.environ.update(self.env)
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        imgs = self.root / "images"
        (imgs / "sub").mkdir(parents=True)
        for name in ("lake-dawn.jpg", "mug.jpg", "sub/mountain-trail.png", "notes.txt"):
            make_image(imgs / name) if not name.endswith(".txt") else (imgs / name).write_text("x")
        self.images = imgs

    def tearDown(self):
        self.api.shutdown()
        self.api.server_close()
        self.tmp.cleanup()
        for k, v in self.old.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v

    def test_live_run_and_resume(self):
        out = self.root / "out.csv"
        code, log = run_cli("run", str(self.images), "--out", str(out), "--about", "A lodge on Mount Hood")
        self.assertEqual(code, 0, log)
        rows = {r["id"]: r for r in read_csv(out)}
        self.assertEqual(set(rows), {"lake-dawn.jpg", "mug.jpg", "sub/mountain-trail.png"})
        self.assertEqual(rows["lake-dawn.jpg"]["mode"], "story")
        self.assertEqual(rows["lake-dawn.jpg"]["personas"], "painter+naturalist")
        self.assertTrue(rows["lake-dawn.jpg"]["alt_text"].startswith("Morning light"))  # "Image of" removed, quotes trimmed
        self.assertEqual(rows["mug.jpg"]["alt_text"], "Mug.jpg on a white background")
        self.assertEqual(rows["mug.jpg"]["mode"], "plain")
        self.assertTrue(any("- About the site: A lodge on Mount Hood" in e["text"] for e in mock_api.LOG))

        before = len(mock_api.LOG)
        code, log = run_cli("run", str(self.images), "--out", str(out))
        self.assertEqual(len(mock_api.LOG), before, "resume should skip finished images")
        self.assertIn("0 image(s) to describe", log)
        self.assertEqual(len(read_csv(out)), 3)

    def test_fixed_persona(self):
        out = self.root / "out.csv"
        run_cli("run", str(self.images), "--out", str(out), "--persona", "philosopher", "--mode", "story")
        rows = {r["id"]: r for r in read_csv(out)}
        self.assertEqual(rows["lake-dawn.jpg"]["personas"], "philosopher")

    def test_batch_run(self):
        out = self.root / "batch.csv"
        code, log = run_cli("run", str(self.images), "--out", str(out), "--batch", "--no-wait")
        self.assertEqual(code, 0, log)
        state = Path(str(out) + ".batch.json")
        self.assertTrue(state.exists())
        code, log = run_cli("collect", str(state), "--poll", "0")
        self.assertEqual(code, 0, log)
        rows = read_csv(out)
        self.assertEqual(len(rows), 3)
        self.assertTrue(all(r["alt_text"] and not r["error"] for r in rows))
        # collecting twice must not duplicate rows
        run_cli("collect", str(state), "--poll", "0")
        self.assertEqual(len(read_csv(out)), 3)

    def test_site_run_skips_present(self):
        site = SiteFixture()
        try:
            out = self.root / "site.csv"
            code, log = run_cli("run", site.base + "/sitemap.xml", "--out", str(out))
            self.assertEqual(code, 0, log)
            names = {Path(r["id"]).name for r in read_csv(out)}
            self.assertEqual(names, {"lake-sunrise.jpg", "kayak.jpg", "trail.jpg"})
            out2 = self.root / "site2.csv"
            run_cli("run", site.base + "/sitemap.xml", "--out", str(out2), "--skip-empty")
            self.assertNotIn("kayak.jpg", {Path(r["id"]).name for r in read_csv(out2)})
        finally:
            site.close()

    def test_bad_key_stops_run(self):
        os.environ["WONDER_ALT_API_URL"] = "http://127.0.0.1:1"  # nothing listening
        code, log = run_cli("describe", str(self.images / "mug.jpg"))
        self.assertEqual(code, 1)

    def test_describe_json(self):
        buf = io.StringIO()
        from contextlib import redirect_stdout
        with redirect_stdout(buf), redirect_stderr(io.StringIO()):
            code = main(["describe", str(self.images / "lake-dawn.jpg"), "--json"])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(buf.getvalue())["mode"], "story")


if __name__ == "__main__":
    unittest.main()
