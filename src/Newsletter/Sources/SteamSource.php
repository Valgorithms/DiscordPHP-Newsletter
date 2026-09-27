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

use Newsletter\Http\JsonClient;
use Newsletter\Window;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * The owner's Steam activity: playtime per game inside the window (the
 * difference between two lifetime-playtime snapshots) and achievements whose
 * unlock time falls inside it.
 *
 * The profile's game details must be public, or the key must belong to the
 * account, for the Web API to return playtime.
 *
 * @link https://developer.valvesoftware.com/wiki/Steam_Web_API#GetOwnedGames_.28v0001.29
 * @link https://developer.valvesoftware.com/wiki/Steam_Web_API#GetPlayerAchievements_.28v0001.29
 *
 * @since 1.0.0
 */
final class SteamSource implements Source
{
    private const API = 'https://api.steampowered.com';

    /** How many of the most-played games get an achievement lookup. */
    private const ACHIEVEMENT_GAMES = 5;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly JsonClient $http,
        private readonly string $apiKey,
        private readonly string $steamId,
        private readonly SteamSnapshots $snapshots,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function name(): string
    {
        return 'steam';
    }

    /**
     * Fetches lifetime playtime for every owned game and records it as a
     * snapshot. The bot calls this hourly so a baseline always exists near the
     * start of the next window.
     *
     * @return PromiseInterface<array<int|string, array{0: int, 1: string}>>
     */
    public function snapshot(): PromiseInterface
    {
        $url = self::API . '/IPlayerService/GetOwnedGames/v1/?' . http_build_query([
            'key' => $this->apiKey,
            'steamid' => $this->steamId,
            'include_appinfo' => 1,
            'include_played_free_games' => 1,
            'skip_unvetted_apps' => 0,
            'format' => 'json',
        ]);

        return $this->http->get($url)->then(function (array $body): array {
            $games = [];
            foreach ((array) ($body['response']['games'] ?? []) as $game) {
                if (isset($game['appid'])) {
                    $games[(string) $game['appid']] = [(int) ($game['playtime_forever'] ?? 0), (string) ($game['name'] ?? "App {$game['appid']}")];
                }
            }
            if ($games === []) {
                throw new \RuntimeException('Steam returned no games; is the profile\'s "Game details" privacy set to Public?');
            }
            $this->snapshots->record(($this->clock)(), $games);

            return $games;
        });
    }

    public function collect(Window $window): PromiseInterface
    {
        $now = ($this->clock)();
        // A past window (a catch-up) ends at a stored snapshot; a current one ends now.
        $endSnapshot = $window->end < $now->modify('-15 minutes')
            ? $this->snapshots->atOrBefore($window->end->modify('+15 minutes'))
            : null;
        $end = $endSnapshot ? resolve($endSnapshot['games']) : $this->snapshot();

        return $end->then(function (array $endGames) use ($window): PromiseInterface {
            $notes = [];
            $base = $this->snapshots->atOrBefore($window->start);
            if ($base === null && ($base = $this->snapshots->firstBetween($window->start, $window->end)) !== null) {
                $notes[] = 'playtime only counted since ' . (new \DateTimeImmutable($base['at']))->setTimezone($window->end->getTimezone())->format('H:i') . ' (first snapshot)';
            }

            $played = $base ? self::deltas($base['games'], $endGames) : [];
            if ($base === null) {
                $notes[] = 'no earlier playtime snapshot yet; daily playtime starts being tracked from today';
            }

            return $this->achievements($window, array_map('strval', array_slice(array_keys($played), 0, self::ACHIEVEMENT_GAMES)), $endGames)
                ->then(fn(array $unlocked): SourceReport => $this->report($played, $unlocked, $notes));
        }, fn(\Throwable $e): SourceReport => SourceReport::failed('steam', 'Steam activity', $e->getMessage()));
    }

    /**
     * Minutes played per game between two snapshots, most-played first.
     *
     * @param array<int|string, array{0: int, 1: string}> $before
     * @param array<int|string, array{0: int, 1: string}> $after
     *
     * @return array<string, array{minutes: int, name: string, lifetime: int}>
     */
    public static function deltas(array $before, array $after): array
    {
        $played = [];
        foreach ($after as $appid => [$minutes, $name]) {
            $delta = $minutes - (int) ($before[$appid][0] ?? 0);
            if ($delta > 0) {
                $played[(string) $appid] = ['minutes' => $delta, 'name' => $name, 'lifetime' => $minutes];
            }
        }
        uasort($played, static fn($a, $b) => $b['minutes'] <=> $a['minutes']);

        return $played;
    }

    /**
     * @param array<string, array{minutes: int, name: string, lifetime: int}> $played
     * @param list<string>                                                    $unlocked
     * @param list<string>                                                    $errors
     */
    public function report(array $played, array $unlocked, array $errors = []): SourceReport
    {
        $highlights = [];
        foreach ($played as $game) {
            $highlights[] = "Played {$game['name']} for " . self::duration($game['minutes']) . ' (' . round($game['lifetime'] / 60) . 'h lifetime)';
        }
        array_push($highlights, ...$unlocked);

        $stats = array_filter([
            'games_played' => count($played),
            'time_played' => $played ? self::duration(array_sum(array_column($played, 'minutes'))) : 0,
            'achievements' => count($unlocked),
        ]);

        return new SourceReport('steam', 'Steam activity', $highlights, $stats, $errors);
    }

    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }

    /**
     * Achievements unlocked inside the window for the given games. A game with
     * no stats (or a private profile) contributes nothing rather than failing.
     *
     * @param list<string>                                $appids
     * @param array<int|string, array{0: int, 1: string}> $names
     *
     * @return PromiseInterface<list<string>>
     */
    private function achievements(Window $window, array $appids, array $names): PromiseInterface
    {
        $lookups = array_map(function (string $appid) use ($window, $names): PromiseInterface {
            $url = self::API . '/ISteamUserStats/GetPlayerAchievements/v1/?' . http_build_query([
                'key' => $this->apiKey,
                'steamid' => $this->steamId,
                'appid' => $appid,
                'l' => 'english',
            ]);

            return $this->http->get($url)->then(static function (array $body) use ($window, $appid, $names): array {
                $lines = [];
                foreach ((array) ($body['playerstats']['achievements'] ?? []) as $a) {
                    if (($a['achieved'] ?? 0) && $window->contains((new \DateTimeImmutable())->setTimestamp((int) ($a['unlocktime'] ?? 0)))) {
                        $game = $names[$appid][1] ?? "App {$appid}";
                        $lines[] = "Unlocked achievement \"" . ($a['name'] ?? $a['apiname'] ?? '?') . "\" in {$game}"
                            . (! empty($a['description']) ? " ({$a['description']})" : '');
                    }
                }

                return $lines;
            }, static fn(): array => []);
        }, $appids);

        return all($lookups)->then(static fn(array $lists): array => array_merge([], ...array_values($lists)));
    }
}
