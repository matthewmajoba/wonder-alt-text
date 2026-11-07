# Wonder Alt-Text

**Alt-text that takes people somewhere.**

Most images on the web have no alt-text, and most of the rest have a flat label like "man holding fishing rod." Wonder Alt-Text finds the gaps and fills them using Claude:

- **Plain mode** for products, logos, icons, screenshots and charts. Clear, accurate and short.
- **Story mode** for the images people come for: a sunrise over the lake, a family on a trail, the view from the top of a hill. One to three sentences that let someone who can't see the picture still hear the light on the water.

Story mode is narrated by one of five original personas, or a blend of two. The model picks the best fit for each image, or you choose.

| Narrator | How it sees |
|---|---|
| **The Stargazer** | Wonder at scale. Connects the small thing in front of you to something vast. |
| **The Neighbor** | Warm and kind. Makes the listener feel welcome and seen. |
| **The Painter** | Gentle and cheerful. Lives in color, light and texture. |
| **The Naturalist** | Precise and lyrical about living things and the land. |
| **The Philosopher** | Calm and present. Invites you to slow down and be here. |

Accuracy always comes first. The prompts forbid inventing details that are not in the image, guessing people's identities, and adding marketing language.

## Pick your path

| You have | Use |
|---|---|
| A WordPress site | [The WordPress plugin](wordpress/README.md). Scans the media library, backfills alt-text, review queue, WP-CLI. |
| Any other site (Shopify, Drupal, Webflow, custom) | [The command-line tool](cli/README.md). Point it at a sitemap, a folder, or a CSV of image URLs, and import the results. |
| A huge library (thousands of images) | The command-line tool with `--batch`, which is about half the cost. WordPress users can `wp wonder-alt export`, run the batch, then `wp wonder-alt import`. |
| A different language or platform | [SPEC.md](SPEC.md). The whole system in plain language, written so you or your AI assistant can rebuild it anywhere. |

## Quick start (any site)

You need Python 3.9+ and an [Anthropic API key](https://console.anthropic.com).

```bash
git clone https://github.com/matthewmajoba/wonder-alt-text.git
cd wonder-alt-text/cli
pip install .            # or skip install and use: python -m wonder_alt
export ANTHROPIC_API_KEY=sk-ant-...

# See what's missing (free, no API calls)
wonder-alt audit https://example.com/sitemap.xml --out audit.csv

# Try one image
wonder-alt describe photo.jpg

# Fill in the gaps, testing on 10 images first
wonder-alt run https://example.com/sitemap.xml --out alt-text.csv --limit 10 --about "A family lodge on Mount Hood"
```

Results land in a CSV with the suggested alt-text, the mode, and the narrator for each image, ready to review and import.

## What it costs

You pay Anthropic directly for API use. With the default model (Claude Sonnet 5.5), each image typically costs well under a cent. Batch mode cuts that roughly in half, and Claude Haiku 5.5 is cheaper still. Check [current pricing](https://docs.claude.com/en/docs/about-claude/models/overview) before a large run, and always try `--limit 10` first.

## How it's built

```
spec/           The shared brain: personas, prompt template, output format (JSON + Markdown)
SPEC.md         How it works, step by step, for humans and AI assistants
cli/            Python command-line tool (no required dependencies)
wordpress/      WordPress plugin
scripts/        sync-spec.sh copies spec/ into each implementation; test.sh runs every check
```

Both implementations read the same `spec/` files, and `scripts/test.sh` checks they build identical prompts. To change a persona or the prompt, edit `spec/`, then run `scripts/sync-spec.sh`.

## Rebuilding it with an AI assistant

Point your assistant at this repository and ask something like:

> Read SPEC.md and the files in spec/ from this repository, then build the same alt-text generator as a Shopify app that reads products from the Admin API.

SPEC.md describes every step, from prompt to output format, so a new implementation behaves the same as these.

## Why

This started with NASA's alt-text for James Webb telescope images: descriptions so vivid that screen reader users got something sighted visitors may never have noticed. Every image is a little piece of the world, and it's worth describing that way.

## Contributing

Issues and pull requests are welcome, especially new CMS adapters and feedback from screen reader users. Run `scripts/test.sh` before opening a pull request.

## License

MIT. See [LICENSE](LICENSE).
