# Wonder Alt-Text for WordPress

Finds every image in your media library that is missing alt-text and fills it in.

## Install

1. Copy the `wonder-alt-text` folder into `wp-content/plugins/`, or zip it and upload it under **Plugins → Add New → Upload Plugin**.
2. Activate **Wonder Alt-Text**.
3. Go to **Settings → Wonder Alt-Text** and add your [Anthropic API key](https://console.anthropic.com). For better security, put it in `wp-config.php` instead:

   ```php
   define( 'WONDER_ALT_API_KEY', 'sk-ant-...' );
   ```

4. Optional: fill in **About this site** with one line, like "A family lodge on Mount Hood with ski runs and hiking trails." It helps the model name places correctly.

## Use it

- **Backfill the whole library:** **Media → Wonder Alt-Text → Start backfill.** It works through every image with missing or empty alt-text, a few at a time, and you can stop and resume whenever you like.
- **Review queue:** with review mode on (the default), new alt-text waits on the same page until you approve it. You can edit the text, rewrite it, reject it, or approve everything at once.
- **One image:** open any image in the media library. The **Wonder Alt-Text** field lets you choose a style and narrator, then **Write alt-text** fills in the Alt Text field for you to check before saving.
- **New uploads:** turn on **Generate alt-text automatically when new images are uploaded** in settings.

## WP-CLI

Best for large libraries, since it isn't limited by browser or server timeouts.

```bash
wp wonder-alt status
wp wonder-alt backfill --limit=20          # test run, results go to the review queue
wp wonder-alt backfill --apply             # save straight to images
wp wonder-alt generate 123 456 --persona=painter
wp wonder-alt approve-all

# Very large libraries: use the standalone tool's batch mode (about half the cost)
wp wonder-alt export images.csv
wonder-alt run images.csv --out results.csv --batch
wp wonder-alt import results.csv
```

## For developers

| Filter | |
|---|---|
| `wonder_alt_personas` | Add, remove or rewrite narrators. |
| `wonder_alt_system_prompt` | Adjust the system prompt. |
| `wonder_alt_request_body` | Change the API request before it is sent. |
| `wonder_alt_result` | Change the result before it is saved. |
| `wonder_alt_models` | Change the model dropdown. |

Plugin data is stored in post meta: `_wonder_alt_suggestion` (review queue), `_wonder_alt_info` (what was written and by which narrator), and `_wonder_alt_error`. Uninstalling removes these and the settings, and keeps the alt-text itself.

The prompt and personas come from `spec/`, a copy of the repository's shared spec. Edit the files in the repository root's `spec/`, then run `scripts/sync-spec.sh`.

## Testing

`tests/harness.php` runs the generator outside WordPress. `scripts/test.sh` at the repository root uses it to check that the plugin and the command-line tool build identical prompts.
