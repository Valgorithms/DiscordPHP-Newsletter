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
  which local models handle far more reliably. It leads with a concrete result, combines duplicate coverage, avoids
  generic busy-day openings and hype, and writes less when there is little new information.
- **Only your work, never its own.** The commits that publish an edition to the websites, and anything said in the
  newsletter channel, are not reported as your work. From the bot's own repository (`NEWSLETTER_REPO`) only commits
  that change what it does are reported: a commit whose message is not routine (docs, formatting, tests, merges) and
  that changes source code outside the tests.
- **It remembers what it already said.** Before writing, the model reads the last few published editions
  (`NEWSLETTER_HISTORY`, 5 by default). It avoids their headlines and phrasing, does not announce the same update
  twice, and presents more work on something already reported as progress on it.
- **Checks the original evidence.** The final review and owner-requested revisions use the saved source reports,
  not just the model's summaries. Opening, merging, releasing, deploying and verifying are kept distinct.
  Older editions without reports still use their saved notes. The original 80-highlight prompt cap is retained;
  omitted items and source failures are marked as incomplete evidence.
- **Missing data is not a quiet day.** When sources fail without recording activity, the deterministic draft says
  the summary is incomplete. A quiet-day draft means nothing was recorded in the available logs, not that no work
  happened. Partial source failures also reach the composition and review prompts without becoming a story about
  API error diagnostics.
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

**Telegram.** Approved editions are also posted to each chat in `TELEGRAM_CHATS`: public channels or groups as
`@name` (or a t.me link), private ones by their `-100…` id. Create a bot with @BotFather, or reuse one you already
have. Its token goes in `TELEGRAM_BOT_TOKEN`, and the bot must be an admin of each chat that may post. The
newsletter is sent as formatted text (bold headline and section titles, clickable GitHub references and links),
split between paragraphs when it is longer than one message. Publishing an edition again edits its messages
instead of sending new ones. The bot only sends and edits, so a token shared with another bot (such as a bridge)
is fine. At startup it logs `Telegram publishing is ready`, or which chat will fail and why.

**Websites.** An approved edition is also committed to the Newsletter pages of
[discordphp.org](https://discordphp.org/newsletter.html) and [valgorithms.com](https://www.valgorithms.com/newsletter.html).
Set `PUBLISH_TARGETS` (`owner/repo[@branch]:path.json`, comma-separated) and a token that can write each repository's
contents. The push to each site's `main` rebuilds and deploys it. If a site fails (an expired token, say), the Discord
post stands, the DM says which site failed, and `!publish` retries.

DM commands (from you only): `!generate` drafts today-so-far on demand, `!status` lists drafts waiting on you, `!rewrite [date]` has the model write the waiting draft again from the same activity, `!publish [date]` retries publishing a
posted edition to following servers, the websites, Reddit and Telegram, and `!help`.

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
| `src/Newsletter/TelegramPublisher.php` | Posts approved editions to Telegram channels and groups through the Bot API |
| `src/Newsletter/SitePublisher.php` | Commits approved editions to each website's `newsletter.json` (GitHub contents API) |
| `src/Newsletter/Bot/ApprovalFlow.php` | DMs, buttons, the edit modal, DM replies and commands, and posting |
| `src/Newsletter/Bot/ActivityRecorder.php` | Gateway listeners that record the day, plus audit-log lookups |
| `src/Newsletter/Bot/Scheduler.php` | Daily timer on the bot's event loop, with catch-up |
| `src/Newsletter/Bot/Renderer.php` | Components V2 cards, trimmed to Discord's 4000-character limit |
| `src/Newsletter/Llm/OllamaClient.php` | Async Ollama client (ported from DiscordPHP-NHA) |

`composer unit` runs the tests without network, Discord or Ollama. Every HTTP and LLM call goes through an injectable
transport.


### Local writing evaluation

    php scripts/evaluate-writer.php --url=http://localhost:11434 --model=your-local-model --output=sample.json

Run this to exercise notes, composition and factual review sequentially against a local model. The runner uses
synthetic activity and a previous-edition fixture, reads no .env or bot state, and creates no publication clients.
Its output includes a checklist for manually assessing accuracy, new progress, duplicate coverage and tone.
This is an opt-in generation test, separate from composer unit; it does not establish that every future
model reply will follow the prompts. Loopback/private IPv4 endpoints are required.


### Open Collective supporter drafts

Set OPENCOLLECTIVE_COLLECTIVE=discordphp and OPENCOLLECTIVE_PERSONAL_TOKEN in your private .env to enable
owner-only !collective [date] (the latest posted edition when no date is given). The local model adapts only
that already-approved edition for DiscordPHP supporters, leaving unrelated gaming/projects out and keeping
donation, expense and budget claims grounded in the selected text. It then reviews the result against that edition.

The bot creates an unpublished update draft and returns its Open Collective link. Review/edit it there and
explicitly choose publication and notification recipients. Normal newsletter approval and !publish do not create
Open Collective updates. This adapter never calls publishUpdate or sends supporter emails.

Use a personal token with updates scope belonging to a Collective admin/community manager. Never put it in
website code, build variables or public files; financial scopes and advanced financial privileges are unnecessary.
The GraphQL contract was verified against opencollective/opencollective-api revision
6f588a91e70e1c7cea7ca326f2852af6d2384acf (UpdateMutations.js, UpdateCreateInput.js and common/update.ts).
Draft creation is separate from publication:
https://documentation.opencollective.com/advanced/keeping-your-community-updated/updates-and-contact

Repeated commands return the saved draft instead of creating another, including after restart. Before creating
a remote update the bot saves a creation_started marker and generated content. If the request result is lost or
cannot be confirmed, it will not retry automatically. Inspect the collective for the possible draft first; after
confirming no draft exists, an administrator can remove only that edition's collective_drafts.discordphp marker
from backed-up bot state while the bot is stopped. If a draft exists, review it in Open Collective instead of
resubmitting. No existing contributor updates are edited or deleted by this integration.

### Activity after an early post

Manual and scheduled drafts exclude activity already covered by a posted edition. After an early
post, the next collection starts at that edition's activity-window end, preserving work done between
drafting and approval. The normal schedule is unchanged. Pending or rejected drafts do not advance
this published cutoff; a pending draft that overlaps a newer post must be regenerated before approval.
The cutoff survives restarts and pruning. Earlier newsletters remain writing context, so a follow-up
may mention earlier work while identifying only newly collected progress as new work. An entirely
covered window is declined rather than collected again. No additional environment settings are needed.

### Source measurement limits

Steam playtime is a difference between saved lifetime totals, not a session log. Missing per-game
baselines are unknown and excluded, and historical editions never substitute current totals for a
missing historical endpoint. Snapshot times can differ from the requested window; those periods are
marked approximate/incomplete rather than asserted as exact daily totals. GitHub title enrichment
preserves event-time status; a later merge cannot turn an earlier close into a merge event.

The owner still reviews every draft. If the enabled local LLM fact-check fails, the generated prose is replaced with a
source-based activity list and the approval message explains that the factual review failed.
Supporter-update creation stops when its factual review fails; retry after the model is available. GitHub feeds/search and Discord gateway observations are
bounded sources, not a complete audit of all work. This does not establish inactivity outside the
recorded evidence. No new environment settings are required for these measurement rules.

### Bridge conversations and developer background

The optional Bridge feed fills a deliberate gap: Discord webhook relays are otherwise
ignored by the Discord recorder. It imports only the owner's own message text, matched
by stable platform account ID in explicitly approved public rooms. It never matches a
webhook display name, reads tokens or Bridge routing storage, or copies other people's
quoted messages, attachments, or private rooms. Configure BridgeBot's export first,
then set `NEWSLETTER_BRIDGE_FEED` to the same absolute JSON-file path in `env.example`.
The file must be local/readable by the Newsletter process. No Bridge package dependency
or extra Discord connection is needed. Both bots need a restart after configuration.

The feed starts when enabled; Bridge does not retain an archive to backfill. Restart,
stale heartbeat and capacity gaps are reported as partial coverage. Current excerpts
replace edits and disappear on reported deletion/purge. Published editions retain their
original evidence; deleting a message does not retroactively edit an approved post.
Messages are bounded to 600 characters and 500 records/14 days in the exporter.
The source shows at most 60 excerpts. Event time is Bridge observation time, not a
claim about the original platform's delivery time. Existing-message edits are labelled
as revisions; collection uses the same half-open published cutoff as other sources.
Conversation is self-reported context, not independent proof that code shipped.

For stable context, copy `developer-context.example.json` to an ignored file such as
`var/developer-context.json`, review/add public project facts with `source_url` provenance,
and set `NEWSLETTER_CONTEXT_FILE` to its absolute path. Include project purpose, public
roles and terminology that help readers understand observed activity. Do not add secrets,
private chats, guessed motivations or outdated promises. This file is operator-curated;
URLs are provenance labels, not automatically fetched or verified. At most 40 facts of
700 characters are used. Both local JSON readers reject files over 2 MB.

Background is saved alongside each edition's original source reports so factual review
and revisions use the same snapshot. It does not generate activity notes, create a
section in deterministic drafts, or turn a quiet day into an announcement. The model
may use it to explain a project with current activity, never to announce old work as new.
All text remains data rather than prompt instructions; owner approval still gates posts.
