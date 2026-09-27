# DiscordPHP-Newsletter

A [DiscordPHP](https://github.com/discord-php/DiscordPHP) bot that writes a **daily newsletter of your day** from your
GitHub, Discord and Steam activity, using **your own locally hosted Ollama model** (no hosted LLMs). It **DMs the draft to
you for approval**. You can approve it, skip it, or reply in plain words with the edits you want. The local model then applies
them and asks again. Only an approved draft is posted to your newsletter channel.

The Ollama client is the same one [DiscordPHP-NHA](https://github.com/Valgorithms/DiscordPHP-NHA)'s autoplayer uses:
native `/api/chat` for a bare origin, OpenAI-compatible `/v1/chat/completions` for a URL ending in `/v1`.

## How a day works

```
 all day                                 NEWSLETTER_TIME (18:00 Eastern)
 ───────────────────────────────────►   ┌─────────────────────────────────────────────────────┐
 gateway → ActivityRecorder             │ collect (parallel)                                  │
   your messages, threads, voice,       │   GitHub  events feed + commit search               │
   per-server message/join counts       │   Discord activity log + your audit-log actions     │
   → var/discord-activity.jsonl         │   Steam   playtime delta + achievements unlocked    │
 hourly Steam playtime snapshot         │ write (local Ollama, one call at a time)            │
   → var/state.json                     │   notes per source → prose draft → fact-check       │
                                        │ DM the draft to you  ──►  ✅ Approve & post         │
                                        │                           ✏️ Request edits (modal)  │
                                        │                           🗑️ Skip                    │
                                        │                           or just reply in words    │
                                        └─────────────────────────────────────────────────────┘
```

- **Replies in plain words** ("good, but drop the Steam part and mention the 10.20 release") are classified by the local
  model: approve, skip, or edit. Edits are applied to the draft JSON, checked against the day's facts, and sent back as a
  new revision. Unambiguous replies (`approve`, `lgtm`, `✅`, `skip`) skip the model entirely.
- **Nothing is ever posted on a guess.** If the model only *infers* approval from a chatty reply, the bot asks you to say
  `approve` or press the button.
- **Buttons survive restarts.** State lives in `var/state.json`, and button ids carry the edition and revision, so an old
  revision's buttons can't post an outdated draft.
- **Plain-English prose, not a changelog.** The model first turns each source's raw activity into themes ("reviewed
  community PRs on command handling"), then writes a few paragraphs about what the day's work was about and why it
  mattered. It names at most a couple of highlights, never every PR or commit. It writes Markdown rather than JSON,
  which local models handle far more reliably.
- **If Ollama is down**, you still get the raw activity list so a day is never lost. The DM says the model didn't
  write it and why, and `!rewrite` asks the model again once it's back. `bot.php` and `preview.php` check at startup
  that Ollama answers and has your model, and say exactly what to fix if not.
- **Catch-up.** If the bot was offline at the scheduled time, it drafts the missed edition on startup. Each window starts
  where the previous one ended.

**Announcement channels.** If the newsletter channel is an announcement channel, the bot also publishes each
approved newsletter to the servers that follow it, straight after posting. The bot only needs *Send Messages* there
for its own posts. If that fails, the DM says why, and `!publish` retries it along with the websites.

**Reddit.** Approved editions are also posted as text posts to each target in `REDDIT_TARGETS`: subreddits such
as `r/ValZarGaming`, and the posting account's own profile as `u/<name>` (Reddit allows no other profile). The headline (with the date) becomes the title, and
`owner/repo#123` references become GitHub links. Publishing the same edition again edits its posts instead of adding
new ones. To set it up:

1. Reddit requires a separate **bot account** registered for API access (its Responsible Builder Policy; your own
   account can't be registered). Create one (e.g. u/Valgorithms), make it a moderator of the subreddit so its posts
   skip the spam filter, and register it while signed in as yourself. Once approved, sign in as the bot and create a
   **script** app on <https://www.reddit.com/prefs/apps> with the redirect uri `http://localhost:65010/reddit-token`.
   Put the app's id (under its name) and secret in `REDDIT_CLIENT_ID` / `REDDIT_CLIENT_SECRET`, and the bot's
   username in `REDDIT_USERNAME`.
2. Sign-in: run `php reddit-token.php` once, signed into Reddit as the bot, to get a `REDDIT_REFRESH_TOKEN`. That works with
   two-factor authentication. Without 2FA, `REDDIT_PASSWORD` works too.
3. At startup the bot logs `Reddit publishing is ready`, or which target will fail and why.

**Websites.** An approved edition is also committed to the Newsletter pages of
[discordphp.org](https://discordphp.org/newsletter.html) and [valgorithms.com](https://www.valgorithms.com/newsletter.html).
Set `PUBLISH_TARGETS` (`owner/repo[@branch]:path.json`, comma-separated) and a token that can write each repository's
contents. The push to each site's `main` rebuilds and deploys it. If a site fails (an expired token, say), the Discord
post stands, the DM says which site failed, and `!publish` retries.

DM commands (from you only): `!generate` drafts today-so-far on demand, `!status` lists drafts waiting on you, `!rewrite [date]` has the model write the waiting draft again from the same activity, `!publish [date]` retries publishing a
posted edition to following servers, the websites and Reddit, and `!help`.

## Setup

```
composer install
cp env.example .env      # fill in TOKEN, OWNER_ID, NEWSLETTER_CHANNEL_ID, OLLAMA_URL, GITHUB_*, STEAM_*
php preview.php --facts  # dry run: prints the facts, the model's notes and the draft; nothing is sent
php bot.php
```

1. **Discord.** Create a bot, enable the **Message Content Intent** (and the **Server Members Intent** if you set
   `DISCORD_TRACK_MEMBERS=1`), and invite it to every server you want covered (DiscordPHP, Valgorithms, …) with
   *View Channels*, *Read Message History* and *View Audit Log*, plus *Send Messages* in the newsletter channel. Set
   `DISCORD_GUILD_IDS` to limit tracking, and `DISCORD_IGNORE_CHANNEL_IDS` for staff or private channels.
2. **Ollama.** `ollama pull gemma3:27b` (or any model) and point `OLLAMA_URL` at it. Anything that follows instructions
   and emits JSON well works. Bigger models write better recaps, and `OLLAMA_TIMEOUT` defaults to 5 minutes per call.
3. **GitHub.** Set `GITHUB_USERNAME`. A read-only token is optional but raises the rate limit. Private-repo activity is
   excluded unless `GITHUB_INCLUDE_PRIVATE=1`.
4. **Steam.** Get a Web API key and your 64-bit SteamID. Your profile's *Game details* must be public. Steam only reports
   lifetime playtime, so daily playtime is the difference between hourly snapshots. The first day after install only
   counts from the first snapshot.

### Standalone binaries

[phpacker](https://github.com/phpacker/phpacker) packs `bot.php` (and the `preview.php` dry run) with its own PHP
into a single executable, so the machine running the bot needs no PHP install:

```
composer phpacker            # both entry points, every platform
composer phpacker:bot        # bot.php     → bin/build/bot/<platform>/
composer phpacker:preview    # preview.php → bin/build/preview/<platform>/
```

A binary finds `vendor/`, `.env` and `var/` by walking up from the executable (then from the working directory), so
it runs from `bin/build/...`, or from a shortcut in any folder, as long as it stays inside the checkout. `bin/build`
is gitignored. **Never commit or share a built binary**: it can be decompiled, and it runs with your `.env`.

phpacker downloads its PHP builds from GitHub the first time. If that fails with "Failed to fetch release data",
GitHub has rate-limited you: give phpacker a token. It reads `GITHUB_TOKEN` from PHP's `$_ENV`, which Windows' default
`variables_order` leaves empty, so pass that setting too (PowerShell):

```
$env:GITHUB_TOKEN = '<a GitHub token>'; php -d variables_order=EGPCS vendor/bin/phpacker build --src=bot.php --dest=bin/build/bot
```

### Class reference

The class reference is built with [phpDocumentor](https://www.phpdoc.org/) into `build/`:

```
mkdir tools
curl -L -o tools/phpDocumentor https://github.com/phpDocumentor/phpDocumentor/releases/latest/download/phpDocumentor.phar
composer docs
```

(or `phive install phpDocumentor`). On Windows, use `Invoke-WebRequest -OutFile tools\phpDocumentor <url>` in
PowerShell instead of `curl -L -o`. `.github/workflows/docs.yml` builds it and publishes it to the `gh-pages` branch
on a release, a manual run, or a push whose commit message contains "build docs".

## Privacy notes

- Only **your** messages are recorded with text (clipped to 300 characters) in `var/discord-activity.jsonl`, pruned
  after 7 days. Other members appear only as per-server counts.
- Everything the model sees stays on your machine: prompts go to your Ollama server and nowhere else.
- The approval step is the final safeguard. Read the draft before approving: it can quote your messages from any tracked
  channel that isn't in `DISCORD_IGNORE_CHANNEL_IDS`.

## Layout

| Path | What it does |
| --- | --- |
| `src/Newsletter/Sources/` | `GitHubSource`, `SteamSource` (+ `SteamSnapshots`), `DiscordSource` (+ `DiscordActivityLog`); each returns a `SourceReport` of plain fact lines |
| `src/Newsletter/Writer.php` | The LLM chain: per-source themes → Markdown prose draft → fact-check → revise on request, with a template fallback that says why |
| `src/Newsletter/ReplyInterpreter.php` | Approve / skip / edit classification of free-text DM replies |
| `src/Newsletter/Pipeline.php` | Collect → write → store an edition |
| `src/Newsletter/SitePublisher.php` | Commits approved editions to each website's `newsletter.json` (GitHub contents API) |
| `src/Newsletter/Bot/ApprovalFlow.php` | DMs, buttons, the edit modal, DM replies and commands, and posting |
| `src/Newsletter/Bot/ActivityRecorder.php` | Gateway listeners that record the day, plus audit-log lookups |
| `src/Newsletter/Bot/Scheduler.php` | Daily timer on the bot's event loop, with catch-up |
| `src/Newsletter/Bot/Renderer.php` | Components V2 cards, trimmed to Discord's 4000-character limit |
| `src/Newsletter/Llm/OllamaClient.php` | Async Ollama client (ported from DiscordPHP-NHA) |

`composer unit` runs the tests without network, Discord or Ollama. Every HTTP and LLM call goes through an injectable
transport.
