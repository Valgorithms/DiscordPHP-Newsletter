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
   → var/state.json                     │   notes per source → compose JSON → fact-check      │
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
- **If Ollama is down**, you still get a plain template draft built from the facts, so a day is never lost.
- **Catch-up.** If the bot was offline at the scheduled time, it drafts the missed edition on startup. Each window starts
  where the previous one ended.

**Websites.** An approved edition is also committed to the Newsletter pages of
[discordphp.org](https://discordphp.org/newsletter.html) and [valgorithms.com](https://www.valgorithms.com/newsletter.html).
Set `PUBLISH_TARGETS` (`owner/repo[@branch]:path.json`, comma-separated) and a token that can write each repository's
contents. The push to each site's `main` rebuilds and deploys it. If a site fails (an expired token, say), the Discord
post stands, the DM says which site failed, and `!publish` retries.

DM commands (from you only): `!generate` drafts today-so-far on demand, `!status` lists drafts waiting on you, `!publish [date]` re-publishes a posted edition to the websites, and `!help`.

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
| `src/Newsletter/Writer.php` | The LLM chain: per-source notes → compose `Draft` JSON (schema-constrained) → fact-check → revise on request, with a template fallback |
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
