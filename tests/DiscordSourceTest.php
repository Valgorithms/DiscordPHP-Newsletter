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

use Newsletter\Sources\DiscordActivityLog;
use Newsletter\Sources\DiscordSource;
use Newsletter\Window;

use function React\Promise\reject;
use function React\Promise\resolve;

final class DiscordSourceTest extends TestCase
{
    public function testLogRoundTripAndWindowFiltering(): void
    {
        $log = new DiscordActivityLog(self::tempFile('activity'));
        $log->record('message', ['guild' => 'DiscordPHP', 'channel' => 'general', 'text' => 'yesterday'], new \DateTimeImmutable('2026-09-26T12:00:00Z'));
        $log->record('message', ['guild' => 'DiscordPHP', 'channel' => 'general', 'text' => 'today'], new \DateTimeImmutable('2026-09-27T12:00:00Z'));

        $entries = $log->between(new Window(new \DateTimeImmutable('2026-09-27T00:00:00Z'), new \DateTimeImmutable('2026-09-28T00:00:00Z')));

        $this->assertCount(1, $entries);
        $this->assertSame('today', $entries[0]['text']);
    }

    public function testReportGroupsMessagesVoicePulseAndModeration(): void
    {
        $entries = [
            ['type' => 'message', 'guild' => 'DiscordPHP', 'channel' => 'support', 'text' => 'Try the dev-master branch'],
            ['type' => 'message', 'guild' => 'DiscordPHP', 'channel' => 'support', 'text' => 'Fixed!', 'attachments' => 1],
            ['type' => 'message', 'guild' => 'Valgorithms', 'channel' => 'general', 'text' => ''],
            ['type' => 'thread', 'guild' => 'DiscordPHP', 'channel' => 'support', 'name' => 'Voice rewrite'],
            ['type' => 'voice', 'guild' => 'Valgorithms', 'channel' => 'Lounge', 'minutes' => 50],
            ['type' => 'voice', 'guild' => 'Valgorithms', 'channel' => 'Lounge', 'minutes' => 25],
            ['type' => 'pulse', 'guild' => 'DiscordPHP', 'messages' => 40, 'joins' => 2, 'leaves' => 0, 'channels' => ['support' => 30, 'general' => 10]],
            ['type' => 'pulse', 'guild' => 'DiscordPHP', 'messages' => 10, 'joins' => 1, 'leaves' => 1, 'channels' => ['support' => 10]],
        ];
        $report = (new DiscordSource(new DiscordActivityLog(self::tempFile('unused'))))->report($entries, ['[DiscordPHP] moderation: member ban add → spammer']);

        $this->assertSame([
            '[DiscordPHP #support] started thread "Voice rewrite"',
            '[DiscordPHP #support] sent 2 messages',
            '[Valgorithms #general] sent 1 message',
            '[DiscordPHP #support] said: "Try the dev-master branch"',
            '[DiscordPHP #support] said: "Fixed!" (+1 attachment(s))',
            '[Valgorithms 🔊Lounge] spent 1h 15m in voice',
            '[DiscordPHP] moderation: member ban add → spammer',
            '[DiscordPHP] community pulse: 50 messages from members (busiest: #support 40, #general 10), 3 joined, 1 left',
        ], $report->highlights);
        $this->assertSame(3, $report->stats['messages_sent']);
        $this->assertSame(3, $report->stats['new_members']);
    }

    public function testCollectFlushesFirstAndSurvivesAuditFailures(): void
    {
        $path = self::tempFile('activity');
        $log = new DiscordActivityLog($path);
        $flushed = false;
        $source = new DiscordSource(
            $log,
            static fn() => reject(new \RuntimeException('Missing Access')),
            function () use ($log, &$flushed) {
                $flushed = true;
                $log->record('pulse', ['guild' => 'G', 'messages' => 3]);
            },
        );

        $report = self::settle($source->collect(new Window(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 minute'))));

        $this->assertTrue($flushed);
        $this->assertSame(3, $report->stats['community_messages']);
        $this->assertSame(['audit log: Missing Access'], $report->errors);

        $ok = new DiscordSource($log, static fn() => resolve(['[G] moderation: x']));
        $this->assertContains('[G] moderation: x', self::settle($ok->collect(new Window(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 minute'))))->highlights);
    }
}
