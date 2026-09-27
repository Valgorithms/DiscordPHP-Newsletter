import assert from 'node:assert/strict';
import { test } from 'node:test';

import { body, type Deps, type Edition, fetchFeed, POSTED_KEY, sync, title } from './newsletter.ts';

const edition = (key: string, date: string, extra: Partial<Edition> = {}): Edition => ({
  key,
  date,
  headline: `Headline ${key}`,
  intro: 'Reviewed discord-php/DiscordPHP#1414 and `discord-php/DiscordPHP#1416`.',
  sections: [{ title: 'Web', body: 'Launched the site.' }],
  signoff: 'See you tomorrow!',
  published_at: `${date}T18:05:00-04:00`,
  ...extra,
});

function fakes(feed: Edition[], now = '2026-09-28T23:00:00Z') {
  const store = new Map<string, string>();
  const posts: { title: string; text: string; subredditName: string }[] = [];
  const edits: { id: string; text: string }[] = [];
  const deps: Deps = {
    fetchFeed: async () => feed,
    redis: {
      hGet: async (_key, field) => store.get(field),
      hSet: async (_key, values) => {
        for (const [k, v] of Object.entries(values)) store.set(k, v);
        return 1;
      },
    },
    submitPost: async (opts) => {
      posts.push(opts);
      return { id: `t3_${posts.length}` };
    },
    editPost: async (id, text) => {
      edits.push({ id, text });
    },
    subredditName: 'ValZarGaming',
    now: () => new Date(now),
  };
  return { deps, store, posts, edits };
}

test('title carries the date and body links bare GitHub references', () => {
  const e = edition('2026-09-27-2', '2026-09-27', { headline: 'Deep Dives and Website Updates' });

  assert.equal(title(e), 'Deep Dives and Website Updates (Sep 27, 2026)');
  const text = body(e, 'Also on https://discordphp.org/newsletter.html');
  assert.match(text, /\[discord-php\/DiscordPHP#1414\]\(https:\/\/github\.com\/discord-php\/DiscordPHP\/issues\/1414\)/);
  assert.match(text, /`discord-php\/DiscordPHP#1416`/, 'code spans stay as they are');
  assert.match(text, /## Web\n\nLaunched the site\./);
  assert.match(text, /\*See you tomorrow!\*/);
  assert.ok(text.endsWith('^(Also on https://discordphp.org/newsletter.html)'));
  assert.ok(!text.includes('Deep Dives'), 'the headline is the title');
});

test('posts each recent edition once, oldest first, and skips the archive', async () => {
  const { deps, posts, store } = fakes([
    edition('2026-09-28', '2026-09-28'),
    edition('2026-09-01', '2026-09-01'),
    edition('2026-09-27-2', '2026-09-27'),
  ]);

  assert.deepEqual(await sync(deps), ['posted 2026-09-27-2 as t3_1', 'posted 2026-09-28 as t3_2']);
  assert.deepEqual(posts.map((p) => [p.subredditName, p.title]), [
    ['ValZarGaming', 'Headline 2026-09-27-2 (Sep 27, 2026)'],
    ['ValZarGaming', 'Headline 2026-09-28 (Sep 28, 2026)'],
  ]);
  assert.equal(JSON.parse(store.get('2026-09-28') ?? '{}').id, 't3_2');

  assert.deepEqual(await sync(deps), [], 'a second run posts nothing');
  assert.equal(posts.length, 2);
});

test('an edition published again edits its post', async () => {
  const feed = [edition('2026-09-28', '2026-09-28')];
  const { deps, posts, edits } = fakes(feed);
  await sync(deps);

  feed[0] = edition('2026-09-28', '2026-09-28', { intro: 'Corrected intro.', published_at: '2026-09-28T19:00:00-04:00' });

  assert.deepEqual(await sync(deps), ['updated 2026-09-28 (t3_1)']);
  assert.equal(posts.length, 1);
  assert.match(edits[0]?.text ?? '', /^Corrected intro\./);
});

test('a failed post is reported and retried next run without stopping the others', async () => {
  const { deps, posts } = fakes([edition('2026-09-27', '2026-09-27'), edition('2026-09-28', '2026-09-28')]);
  const submit = deps.submitPost;
  let fail = true;
  deps.submitPost = async (opts) => {
    if (fail && opts.title.includes('2026-09-27')) throw new Error('RATELIMIT');
    return submit(opts);
  };

  assert.deepEqual(await sync(deps), ['failed 2026-09-27: RATELIMIT', 'posted 2026-09-28 as t3_1']);
  fail = false;
  assert.deepEqual(await sync(deps), ['posted 2026-09-27 as t3_2']);
  assert.equal(posts.length, 2);
});

test('fetchFeed keeps only well-formed editions and reports HTTP errors', async () => {
  const ok = async () =>
    new Response(JSON.stringify({ editions: [edition('2026-09-28', '2026-09-28'), { key: 'x' }, null, { key: 'y', date: 'soon', headline: 'h' }] }));
  assert.deepEqual((await fetchFeed('https://x', ok as typeof fetch)).map((e) => e.key), ['2026-09-28']);

  const down = async () => new Response('', { status: 503 });
  await assert.rejects(fetchFeed('https://x', down as typeof fetch), /HTTP 503/);
});

test('POSTED_KEY is stable, since changing it would repost everything', () => {
  assert.equal(POSTED_KEY, 'newsletter:posted');
});
