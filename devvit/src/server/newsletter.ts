/*
 * Posts the approved daily newsletter to the subreddit this app is installed in.
 *
 * The DiscordPHP-Newsletter bot publishes each approved edition to a public JSON
 * feed (discordphp.org's data/newsletter.json). A scheduled task reads the feed and,
 * for each edition it has not seen, submits a text post as the app account; an
 * edition published again with new content edits its post instead. Redis remembers
 * what was posted, so an edition is never posted twice.
 *
 * Everything in this file that touches Devvit takes its clients as arguments, so
 * the logic runs under `node --test` with plain fakes.
 */

/** One edition as the newsletter bot publishes it. */
export type Edition = {
  key: string;
  date: string;
  headline: string;
  intro?: string;
  sections?: { title?: string; body?: string }[];
  signoff?: string;
  published_at?: string;
};

/** What is remembered per edition: the post, and which publish it shows. */
type Posted = { id: string; publishedAt: string };

/** The parts of Devvit's clients this module uses. */
export type Deps = {
  fetchFeed: () => Promise<Edition[]>;
  redis: {
    hGet(key: string, field: string): Promise<string | undefined>;
    hSet(key: string, fieldValues: Record<string, string>): Promise<number>;
  };
  submitPost: (opts: { subredditName: string; title: string; text: string }) => Promise<{ id: string }>;
  editPost: (id: string, text: string) => Promise<void>;
  subredditName: string;
  now?: () => Date;
  log?: (message: string) => void;
};

/** Redis hash of edition key → JSON {@link Posted}. */
export const POSTED_KEY = 'newsletter:posted';

/** Editions older than this are never posted, so installing the app does not post the whole archive. */
export const MAX_AGE_DAYS = 3;

/** Reddit's limit on a post title. */
const MAX_TITLE = 300;

/** The newsletter feed. It is on discordphp.org, which `devvit.json` must allow under permissions.http.domains. */
export const FEED_URL = 'https://discordphp.org/data/newsletter.json';

/** Fetches and validates the feed: editions with a key, a date and a headline. */
export async function fetchFeed(url: string = FEED_URL, fetcher: typeof fetch = fetch): Promise<Edition[]> {
  const response = await fetcher(url, { headers: { accept: 'application/json' } });
  if (!response.ok) {
    throw new Error(`The newsletter feed answered HTTP ${response.status}`);
  }
  const body = (await response.json()) as { editions?: unknown };
  const editions = Array.isArray(body.editions) ? body.editions : [];

  return editions.filter(
    (e): e is Edition =>
      typeof e === 'object' && e !== null && typeof e.key === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(String(e.date)) && typeof e.headline === 'string',
  );
}

/** The post title: the headline, dated so a recurring headline is still distinct. */
export function title(edition: Edition): string {
  const day = new Date(`${edition.date}T12:00:00Z`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
  const text = `${edition.headline} (${day})`;

  return text.length > MAX_TITLE ? `${text.slice(0, MAX_TITLE - 1)}…` : text;
}

/**
 * The post body in Reddit Markdown: the newsletter without its headline (that is
 * the title), with bare `owner/repo#123` references linked to GitHub.
 */
export function body(edition: Edition, footer = ''): string {
  const parts: string[] = [];
  if (edition.intro) parts.push(edition.intro);
  for (const section of edition.sections ?? []) {
    if (!section.body) continue;
    parts.push(section.title ? `## ${section.title}\n\n${section.body}` : section.body);
  }
  if (edition.signoff) parts.push(`*${edition.signoff}*`);
  if (footer) parts.push(`---\n\n^(${footer})`);

  return parts.join('\n\n').replace(/(?<![\w/`[])([A-Za-z0-9-]+\/[A-Za-z0-9._-]+)#(\d+)\b(?!`)/g, '[$1#$2](https://github.com/$1/issues/$2)');
}

/**
 * One sync pass: posts every recent edition not posted yet, and edits the post of
 * one published again since. Returns a line per action, for the logs.
 */
export async function sync(deps: Deps, footer = ''): Promise<string[]> {
  const now = deps.now?.() ?? new Date();
  const oldest = new Date(now.getTime() - MAX_AGE_DAYS * 86_400_000).toISOString().slice(0, 10);
  const log = deps.log ?? (() => {});
  const actions: string[] = [];

  // Oldest first, so the subreddit shows the editions in order.
  const editions = (await deps.fetchFeed()).filter((e) => e.date >= oldest).sort((a, b) => (a.date === b.date ? a.key.localeCompare(b.key) : a.date < b.date ? -1 : 1));

  for (const edition of editions) {
    const publishedAt = edition.published_at ?? '';
    const stored = await deps.redis.hGet(POSTED_KEY, edition.key);
    const posted: Posted | undefined = stored ? (JSON.parse(stored) as Posted) : undefined;

    try {
      if (!posted) {
        const post = await deps.submitPost({ subredditName: deps.subredditName, title: title(edition), text: body(edition, footer) });
        await deps.redis.hSet(POSTED_KEY, { [edition.key]: JSON.stringify({ id: post.id, publishedAt } satisfies Posted) });
        actions.push(`posted ${edition.key} as ${post.id}`);
      } else if (publishedAt > posted.publishedAt) {
        await deps.editPost(posted.id, body(edition, footer));
        await deps.redis.hSet(POSTED_KEY, { [edition.key]: JSON.stringify({ id: posted.id, publishedAt } satisfies Posted) });
        actions.push(`updated ${edition.key} (${posted.id})`);
      }
    } catch (error) {
      // One bad edition must not stop the rest; the next run retries it.
      actions.push(`failed ${edition.key}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }
  for (const action of actions) log(`[newsletter] ${action}`);

  return actions;
}
