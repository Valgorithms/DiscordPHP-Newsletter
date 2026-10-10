<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Newsletter project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Newsletter;

/**
 * Tiny JSON-backed store (`var/state.json`) for everything that must survive a
 * restart: editions and their approval state, the end of the last covered
 * window, and Steam playtime snapshots.
 *
 * Writes are atomic (temp file + rename), so a crash mid-save never leaves a
 * truncated file behind.
 *
 * @since 1.0.0
 */
final class StateStore
{
    public const STATUS_DRAFTING = 'drafting';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVISING = 'revising';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';

    /** How many published editions {@see recordPublished()} keeps. */
    public const PUBLISHED_KEPT = 30;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $path)
    {
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->data = is_array($decoded) ? $decoded : [];
        }
    }

    /** Reads a dotted key (`steam.snapshots`), or `$default`. */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /** Writes a dotted key and saves. `null` removes it. */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $node = &$this->data;
        foreach ($parts as $part) {
            if (! isset($node[$part]) || ! is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        if ($value === null) {
            unset($node[$last]);
        } else {
            $node[$last] = $value;
        }
        unset($node);

        $this->save();
    }

    // --- editions -----------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function edition(string $key): ?array
    {
        $edition = $this->get('editions.' . $key);

        return is_array($edition) ? $edition : null;
    }

    /** @param array<string, mixed> $edition Must carry `key`. */
    public function putEdition(array $edition): void
    {
        $edition['updated_at'] = date(DATE_ATOM);
        $this->set('editions.' . $edition['key'], $edition);
    }

    /**
     * An unused edition key for `$date`: the date itself, or `date-2`, `date-3`…
     * when an edition for that day already exists (a catch-up plus the evening run).
     */
    public function freeEditionKey(string $date): string
    {
        $key = $date;
        for ($n = 2; $this->edition($key) !== null; $n++) {
            $key = "{$date}-{$n}";
        }

        return $key;
    }

    /**
     * Editions waiting on the owner, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingEditions(): array
    {
        $pending = array_values(array_filter(
            (array) $this->get('editions', []),
            static fn($e) => is_array($e) && in_array($e['status'] ?? null, [self::STATUS_PENDING, self::STATUS_REVISING], true),
        ));
        usort($pending, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $pending;
    }

    /** The pending edition whose approval DM is `$messageId`, if any. */
    public function editionByDmMessage(string $messageId): ?array
    {
        foreach ((array) $this->get('editions', []) as $edition) {
            if (is_array($edition) && in_array($messageId, (array) ($edition['dm_message_ids'] ?? []), true)) {
                return $edition;
            }
        }

        return null;
    }

    /** Drops editions (and their stored facts) older than `$days`. */
    public function pruneEditions(int $days = 30): void
    {
        $cutoff = date(DATE_ATOM, time() - $days * 86400);
        $editions = array_filter((array) $this->get('editions', []), static fn($e) => ($e['created_at'] ?? '') >= $cutoff);
        $this->set('editions', $editions);
    }

    // --- published editions -------------------------------------------------------

    /**
     * Remembers what an edition said once it is published, for the writer to
     * read before the next one. Kept apart from `editions`, which are pruned
     * after 30 days, and capped at the newest {@see self::PUBLISHED_KEPT}.
     *
     * @param array<string, mixed> $edition A posted edition.
     */
    public function recordPublished(array $edition): void
    {
        $published = array_values(array_filter(
            (array) $this->get('published', []),
            static fn($p) => is_array($p) && ($p['key'] ?? null) !== $edition['key'],
        ));
        $published[] = self::publishedEntry($edition);
        usort($published, self::newestFirst(...));
        $this->set('published', array_slice($published, 0, self::PUBLISHED_KEPT));
    }

    /**
     * The newest published editions, newest first: what readers have already
     * seen. Editions posted before {@see recordPublished()} existed are read
     * from `editions`.
     *
     * @return list<array{key: string, date: string, headline: string, markdown: string, posted_at: string}>
     */
    public function publishedEditions(int $limit, ?string $exceptKey = null): array
    {
        if ($limit <= 0) {
            return [];
        }
        $byKey = [];
        foreach ((array) $this->get('editions', []) as $edition) {
            if (is_array($edition) && ($edition['status'] ?? null) === self::STATUS_POSTED && isset($edition['key'], $edition['draft'])) {
                $byKey[$edition['key']] = self::publishedEntry($edition);
            }
        }
        foreach ((array) $this->get('published', []) as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $byKey[$entry['key']] = $entry;
            }
        }
        unset($byKey[$exceptKey ?? '']);
        $published = array_values($byKey);
        usort($published, self::newestFirst(...));

        return array_slice($published, 0, $limit);
    }

    /**
     * @param array<string, mixed> $edition
     *
     * @return array{key: string, date: string, headline: string, markdown: string, posted_at: string}
     */
    private static function publishedEntry(array $edition): array
    {
        $draft = Draft::fromArray((array) $edition['draft']);
        try {
            $date = Window::fromArray((array) $edition['window'])->label();
        } catch (\Throwable) {
            $date = (string) $edition['key'];
        }

        return [
            'key' => (string) $edition['key'],
            'date' => $date,
            'headline' => $draft->headline,
            'markdown' => $draft->toMarkdown(),
            'posted_at' => (string) ($edition['posted_at'] ?? $edition['updated_at'] ?? $edition['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function newestFirst(array $a, array $b): int
    {
        return strcmp((string) ($b['posted_at'] ?? ''), (string) ($a['posted_at'] ?? ''));
    }

    // --- window ----------------------------------------------------------------

    public function lastWindowEnd(): ?\DateTimeImmutable
    {
        $end = $this->get('last_window_end');

        return is_string($end) ? new \DateTimeImmutable($end) : null;
    }

    public function setLastWindowEnd(\DateTimeImmutable $end): void
    {
        $this->set('last_window_end', $end->format(DATE_ATOM));
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($tmp, $this->path);
    }
}
