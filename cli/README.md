# Wonder Alt-Text: command-line tool

Works with any website or CMS. No required dependencies beyond Python 3.9+. Install Pillow (`pip install ".[resize]"`) so large images get resized before upload, which saves money.

## Install

```bash
cd cli
pip install .
export ANTHROPIC_API_KEY=sk-ant-...
```

Or run it without installing: `python -m wonder_alt ...` from the `cli` folder.

## Commands

| Command | What it does |
|---|---|
| `wonder-alt audit <source>` | Lists every image and whether its alt-text is missing, empty or present. No API calls, free. |
| `wonder-alt describe <image>` | Writes alt-text for one image file or URL and prints it. |
| `wonder-alt run <source> --out results.csv` | Writes alt-text for every image that needs it. |
| `wonder-alt run <source> --out results.csv --batch` | Same, through the Message Batches API: about half the cost, usually done within an hour. |
| `wonder-alt collect results.csv.batch.json` | Downloads results of a batch run started with `--no-wait`. |
| `wonder-alt personas` | Lists the narrators. |
| `wonder-alt prompt` | Prints the exact system prompt, for review. |

## Sources

`<source>` can be:

- **An image file or image URL**: `photo.jpg`, `https://example.com/img/lake.jpg`
- **A folder**: every JPEG, PNG, GIF and WebP inside, including subfolders
- **A web page**: `https://example.com/lodging`. Finds every `<img>`, including lazy-loaded ones
- **A sitemap**: `https://example.com/sitemap.xml`. Scans every page listed, up to `--max-pages` (default 500)
- **A CSV** with a `url` or `path` column, plus optional `id`, `alt`, `title`, `caption`, `description`, `page_url` and `used_on` columns. This is how you connect any CMS: export your media list, run it, import the results.

## Style options

| Option | Default | |
|---|---|---|
| `--mode auto\|plain\|story` | `auto` | Auto uses plain for functional images and story for scenic ones. |
| `--persona auto\|stargazer\|neighbor\|painter\|naturalist\|philosopher` | `auto` | Auto picks or blends the best fit. |
| `--length short\|medium\|long` | `medium` | Story length: 1, 2 or 3 sentences. |
| `--about "..."` | | One line about the site. Helps the model name places and products correctly. |
| `--website "..."` | | Site name. |
| `--language Spanish` | English | Writes alt-text in another language. |
| `--model` | `claude-sonnet-5-5` | Any Claude model ID. Or set `WONDER_ALT_MODEL`. |

## Run options

| Option | |
|---|---|
| `--limit 10` | Stop after 10 images. Always start here. |
| `--overwrite` | Also rewrite images that already have alt-text. |
| `--skip-empty` | Leave `alt=""` images alone (some sites use it for decorative images). |
| `--concurrency 4` | Parallel requests in live mode. |
| `--send-urls` | Let the API fetch public image URLs directly instead of downloading them first. |

Runs are resumable. Re-run the same command with the same `--out` file and finished images are skipped.

## Output

A CSV with `id, source, page_url, alt_status, current_alt, alt_text, mode, personas, image_type, model, error`. Review it in any spreadsheet app, then import it into your CMS.

## Tests

```bash
python -m unittest discover tests -v
```

`tests/mock_api.py` is a fake Claude API, useful for trying the tool without a key:

```bash
python tests/mock_api.py 8765 &
WONDER_ALT_API_URL=http://127.0.0.1:8765 ANTHROPIC_API_KEY=test python -m wonder_alt run ./my-images --out test.csv
```
