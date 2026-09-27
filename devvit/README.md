# vzg-newsletter (Devvit app)

Posts the approved daily newsletter to r/ValZarGaming. Reddit only grants API access to apps on its
Developer Platform (Devvit), so this part runs on Reddit rather than in `bot.php`.

- Every 15 minutes the `newsletter-sync` scheduled task reads `https://discordphp.org/data/newsletter.json`.
  That is the feed the bot publishes approved editions to.
- Each edition from the last 3 days that it has not posted yet becomes a text post by the app account. The
  headline and date are the title, and `owner/repo#123` references become GitHub links.
- An edition published again edits its post. Redis remembers what was posted (`newsletter:posted`).
- Moderators also get a subreddit menu item, **Post the newsletter now**.
- Installing the app posts nothing. The template's demo post on install is removed.

These files go into the project `npm create devvit` made (the counter game's `client/`, `shared/` and `db.ts`
stay as they are):

| File | What changed |
| --- | --- |
| `devvit.json` | `permissions` (fetch from discordphp.org, Redis, Reddit), `scheduler`, the menu item |
| `src/server/server.ts` | the sync route; the install trigger and menu item run the sync instead of making demo posts |
| `src/server/newsletter.ts` | the sync itself |
| `src/server/newsletter.test.ts` | its tests: `node --experimental-strip-types --test src/server/newsletter.test.ts` |

`discordphp.org` must be allowed for the app to fetch the feed. Reddit reviews that domain when the app is uploaded.
