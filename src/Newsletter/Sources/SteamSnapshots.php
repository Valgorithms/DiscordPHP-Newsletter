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

namespace Newsletter\Sources;

use Newsletter\StateStore;

/**
 * Steam only reports lifetime playtime, so a day's playtime is the difference
 * between two snapshots. This keeps those snapshots in the {@see StateStore}.
 *
 * It is deliberately its own class: an always-on collector (for example a
 * Cloudflare Worker cron writing to KV) can feed the same shape of data while
 * the bot's machine is off, without {@see SteamSource} changing.
 *
 * A snapshot is `['at' => ATOM time, 'games' => [appid => [minutes, name]]]`.
 *
 * @since 1.0.0
 */
final class SteamSnapshots
{
    private const KEY = 'steam.snapshots';

    public function __construct(private readonly StateStore $state, private readonly int $keepDays = 14) {}

    /** @param array<int|string, array{0: int, 1: string}> $games */
    public function record(\DateTimeImmutable $at, array $games): void
    {
        $cutoff = $at->modify("-{$this->keepDays} days");
        $snapshots = array_values(array_filter($this->all(), static fn(array $s) => new \DateTimeImmutable($s['at']) >= $cutoff));
        $snapshots[] = ['at' => $at->format(DATE_ATOM), 'games' => $games];
        usort($snapshots, static fn($a, $b) => strcmp($a['at'], $b['at']));
        $this->state->set(self::KEY, $snapshots);
    }

    /**
     * The newest snapshot taken at or before `$time`.
     *
     * @return array{at: string, games: array<int|string, array{0: int, 1: string}>}|null
     */
    public function atOrBefore(\DateTimeImmutable $time): ?array
    {
        $found = null;
        foreach ($this->all() as $snapshot) {
            if (new \DateTimeImmutable($snapshot['at']) <= $time) {
                $found = $snapshot;
            }
        }

        return $found;
    }

    /**
     * The oldest snapshot taken at or after `$time` (and before `$until`).
     *
     * @return array{at: string, games: array<int|string, array{0: int, 1: string}>}|null
     */
    public function firstBetween(\DateTimeImmutable $time, \DateTimeImmutable $until): ?array
    {
        foreach ($this->all() as $snapshot) {
            $at = new \DateTimeImmutable($snapshot['at']);
            if ($at >= $time && $at < $until) {
                return $snapshot;
            }
        }

        return null;
    }

    /** @return list<array{at: string, games: array<int|string, array{0: int, 1: string}>}> */
    private function all(): array
    {
        return array_values(array_filter((array) $this->state->get(self::KEY, []), static fn($s) => is_array($s) && isset($s['at'], $s['games'])));
    }
}
