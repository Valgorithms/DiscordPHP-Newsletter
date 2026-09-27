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

namespace Tests;

use Newsletter\Sources\SteamSnapshots;
use Newsletter\Sources\SteamSource;
use Newsletter\StateStore;
use Newsletter\Window;

final class SteamSourceTest extends TestCase
{
    public function testDeltasAreMinutesPlayedBetweenSnapshotsMostPlayedFirst(): void
    {
        $before = ['10' => [100, 'Factorio'], '20' => [50, 'Terraria']];
        $after = ['10' => [130, 'Factorio'], '20' => [140, 'Terraria'], '30' => [15, 'New Game']];

        $this->assertSame([
            '20' => ['minutes' => 90, 'name' => 'Terraria', 'lifetime' => 140],
            '10' => ['minutes' => 30, 'name' => 'Factorio', 'lifetime' => 130],
            '30' => ['minutes' => 15, 'name' => 'New Game', 'lifetime' => 15],
        ], SteamSource::deltas($before, $after));
    }

    public function testCollectUsesTheSnapshotBeforeTheWindowAndFindsTodaysAchievements(): void
    {
        $tz = new \DateTimeZone('UTC');
        $snapshots = new SteamSnapshots(new StateStore(self::tempFile('state')));
        $snapshots->record(new \DateTimeImmutable('2026-09-26T23:10:00Z'), ['427520' => [600, 'Factorio']]);

        $http = self::fakeHttp([
            'GetOwnedGames' => ['response' => ['games' => [['appid' => 427520, 'name' => 'Factorio', 'playtime_forever' => 685]]]],
            'GetPlayerAchievements' => ['playerstats' => ['achievements' => [
                ['apiname' => 'a', 'name' => 'Steam Age', 'description' => 'Build a steam engine', 'achieved' => 1, 'unlocktime' => strtotime('2026-09-27T15:00:00Z')],
                ['apiname' => 'b', 'name' => 'Old One', 'achieved' => 1, 'unlocktime' => strtotime('2026-01-01T00:00:00Z')],
                ['apiname' => 'c', 'name' => 'Locked', 'achieved' => 0, 'unlocktime' => 0],
            ]]],
        ]);
        $source = new SteamSource($http, 'key', '7656', $snapshots, static fn() => new \DateTimeImmutable('2026-09-27T23:30:00Z'));
        $window = new Window(new \DateTimeImmutable('2026-09-27T00:00:00', $tz), new \DateTimeImmutable('2026-09-27T23:30:00', $tz));

        $report = self::settle($source->collect($window));

        $this->assertSame([
            'Played Factorio for 1h 25m (11h lifetime)',
            'Unlocked achievement "Steam Age" in Factorio (Build a steam engine)',
        ], $report->highlights);
        $this->assertSame(['games_played' => 1, 'time_played' => '1h 25m', 'achievements' => 1], $report->stats);
        $this->assertSame([], $report->errors);
    }

    public function testFirstRunExplainsThatThereIsNoBaselineYet(): void
    {
        $snapshots = new SteamSnapshots(new StateStore(self::tempFile('state')));
        $http = self::fakeHttp(['GetOwnedGames' => ['response' => ['games' => [['appid' => 1, 'name' => 'X', 'playtime_forever' => 5]]]]]);
        $source = new SteamSource($http, 'key', '7656', $snapshots, static fn() => new \DateTimeImmutable('2026-09-27T23:30:00Z'));
        $window = new Window(new \DateTimeImmutable('2026-09-27T00:00:00Z'), new \DateTimeImmutable('2026-09-27T23:30:00Z'));

        $report = self::settle($source->collect($window));

        $this->assertTrue($report->isEmpty());
        $this->assertStringContainsString('no earlier playtime snapshot', $report->errors[0]);
        $this->assertNotNull($snapshots->atOrBefore(new \DateTimeImmutable('2026-09-28T00:00:00Z')), 'the live fetch is recorded as a snapshot');
    }

    public function testPrivateProfileBecomesAnError(): void
    {
        $source = new SteamSource(self::fakeHttp(['GetOwnedGames' => ['response' => []]]), 'k', 'id', new SteamSnapshots(new StateStore(self::tempFile('state'))));
        $report = self::settle($source->collect(new Window(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable())));

        $this->assertStringContainsString('Public', $report->errors[0]);
    }
}
