# Wonder Alt-Text: How It Works

This file describes the whole system in plain language. It is written so a person, or an AI coding assistant, can rebuild Wonder Alt-Text in any language or plug it into any CMS. The `cli/` (Python) and `wordpress/` (PHP) folders are two reference implementations of this spec.

## The idea

Most alt-text is missing, and most of what exists is a flat label. Wonder Alt-Text fills the gaps with two kinds of description:

- **Plain mode** for functional images: products, logos, icons, screenshots, charts, maps. Short, clear, accurate.
- **Story mode** for scenic and emotional images: landscapes, trails, lodges, wildlife, people enjoying a place. One to three sentences that carry a screen reader user into the moment, while staying true to what is in the picture.

Story mode is narrated by one of five original personas, or a blend of two:

| Slug | Name | How it sees |
|---|---|---|
| `stargazer` | The Stargazer | Wonder at scale. Connects the small thing in front of you to something vast. |
| `neighbor` | The Neighbor | Warm and kind. Makes the listener feel welcome and seen. |
| `painter` | The Painter | Gentle and cheerful. Lives in color, light and texture. |
| `naturalist` | The Naturalist | Precise and lyrical about living things and the land. |
| `philosopher` | The Philosopher | Calm and present. Invites you to slow down and be here. |

The personas are original characters defined only by a way of seeing. Prompts never name, quote, or imitate real people.

## Files in `spec/`

| File | What it holds |
|---|---|
| `personas.json` | The five narrators: `slug`, `name`, `tagline`, `voice`. Add, edit or remove personas here. |
| `system-prompt.md` | The system prompt template, with `{{placeholders}}`. |
| `prompt-parts.json` | The text that fills each placeholder, plus the user-message header and the list of image types. |
| `tool.json` | The tool definition that forces a structured answer. |

Implementations must read these files rather than copy their text into code, so every implementation stays in sync.

## Inputs for one image

| Input | Values | Default |
|---|---|---|
| `mode` | `auto`, `plain`, `story` | `auto` |
| `persona` | `auto` or a persona slug | `auto` |
| `story_length` | `short`, `medium`, `long` | `medium` |
| `language` | Any language name or code, or empty for English | empty |
| context | Optional facts: site name, what the site is about, file name, title, caption, the page the image appears on | none |

## Step 1: Build the system prompt

Start with `system-prompt.md` and replace each placeholder:

| Placeholder | Replace with |
|---|---|
| `{{story_length}}` | `prompt-parts.story_length[story_length]` |
| `{{mode_instruction}}` | `prompt-parts.mode_instruction[mode]` |
| `{{narrator_list}}` | One line per persona, in file order, from `prompt-parts.narrator_line`, filling `{{slug}}`, `{{name}}`, `{{voice}}`. Lines are joined with a single newline. |
| `{{narrator_instruction}}` | If `persona` is `auto`, use `narrator_instruction.auto`. Otherwise use `narrator_instruction.fixed` with `{{persona}}` set to the slug. |
| `{{language_instruction}}` | Empty if no language, or if it is English. Otherwise `language_instruction` with `{{language}}` filled in. |

Then collapse any run of three or more newlines into exactly two, and trim the result.

## Step 2: Build the user message

The user message has two content blocks, image first:

1. **The image.** Either base64 (`{"type":"image","source":{"type":"base64","media_type":"image/jpeg","data":"..."}}`) or a public URL (`{"type":"image","source":{"type":"url","url":"https://..."}}`). Supported: JPEG, PNG, GIF (first frame) and WebP. Resize so the long edge is at most 1568 px before sending. It saves tokens and loses nothing.
2. **The context text.** `prompt-parts.user_message_header`, then one line per known fact in the form `- Label: value`. Use these labels when available: `Website`, `About the site`, `File name`, `Media title`, `Caption`, `Description`, `Used on`. Skip any that are unknown.

## Step 3: Call the model

Send a Claude Messages API request:

```json
{
  "model": "claude-sonnet-5-5",
  "max_tokens": 1024,
  "system": "<system prompt from step 1>",
  "tools": [ <tool.json, with personas.items.enum set to the persona slugs> ],
  "tool_choice": { "type": "tool", "name": "save_alt_text" },
  "messages": [ { "role": "user", "content": [ <image block>, <context text block> ] } ]
}
```

`tool_choice` forces the model to answer by calling `save_alt_text`, so the reply is always structured. Retry on HTTP 429, 529 and 5xx with a short backoff.

For large libraries, use the Message Batches API instead (`POST /v1/messages/batches`). Each request gets a `custom_id` (the image's ID) and the same `params` as above. Batches cost about half as much and usually finish within an hour.

## Step 4: Read and clean the answer

Find the `tool_use` content block named `save_alt_text` and read its `input`:

- `alt_text`: strip HTML tags, decode HTML entities, collapse whitespace, remove quotes only when a matching pair wraps the whole text, remove a leading "Image of", "Picture of", "Photo of", "Photograph of" or "... showing", and capitalize the first letter. If it is empty, treat it as an error.
- `mode`: `story` or `plain`. If the requested mode was `plain`, force `plain`.
- `personas`: keep only known slugs, at most two, no duplicates. Empty in plain mode. If a fixed persona was requested, report that persona.
- `image_type`: keep it if it is in `prompt-parts.image_types`, otherwise `other`.

## Step 5: Save it

Every implementation should support:

- **Only missing.** By default, process images with no alt-text or an empty `alt=""`. Rewriting existing alt-text is opt-in, and so is skipping empty alt.
- **Review first.** By default, put results in a review step (a queue, or a spreadsheet) before they go live. People should approve what is published.
- **Resume.** Large runs should skip images already processed, so a crash or timeout never means starting over.
- **Record what happened.** Store mode, persona(s), image type and model next to each result.

## Output format for non-CMS tools

A CSV with these columns, in this order:

`id, source, page_url, alt_status, current_alt, alt_text, mode, personas, image_type, model, error`

- `id`: a stable identifier (file path, media ID or image URL).
- `source`: where the image bytes came from (path or URL).
- `page_url`: the page the image was found on, if any.
- `alt_status`: `missing` (no alt attribute), `empty` (`alt=""`), or `present`.
- `current_alt`: the alt-text the image has now, if any.
- `personas`: slugs joined with `+`.
- `error`: empty on success.

Site owners import this into their CMS, or a CMS adapter writes it straight back.

## Adding a new CMS

An adapter only needs to do three things:

1. List images, with their current alt-text and any context (title, caption, page).
2. Run steps 1 to 4 for each one that needs alt-text.
3. Write the result back, ideally behind a review step.

Shopify, Drupal, Contentful, Sanity, Webflow and others all expose media through an API, so each adapter is usually a small script on top of the CLI's CSV output.
