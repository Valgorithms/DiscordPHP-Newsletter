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

use Newsletter\Bot\Renderer;
use Newsletter\Bot\Scheduler;
use Newsletter\Draft;
use Newsletter\StateStore;
use Newsletter\Window;

final class BotTest extends TestCase
{
    public function testCustomIdsRoundTrip(): void
    {
        $this->assertSame(['action' => 'approve', 'key' => '2026-09-27-2', 'revision' => 3], Renderer::parseCustomId('newsletter:approve:2026-09-27-2:3'));
        $this->assertNull(Renderer::parseCustomId('something:else'));
        $this->assertNull(Renderer::parseCustomId('newsletter:delete:2026-09-27:1'));
    }

    public function testBlocksAreTrimmedToTheTextBudget(): void
    {
        $draft = new Draft('Headline', 'Intro', [
            ['title' => 'Long', 'body' => str_repeat('a', 3000)],
            ['title' => 'Also long', 'body' => str_repeat('b', 2000)],
            ['title' => 'Short', 'body' => 'short'],
        ], 'Bye');

        $blocks = Renderer::blocks($draft, '-# footer', 1500);

        $this->assertLessThanOrEqual(1500, array_sum(array_map('mb_strlen', $blocks)));
        $this->assertStringContainsString('## Short', implode("\n", $blocks));
        $this->assertStringEndsWith('…', $blocks[1]);
    }

    public function testApprovalMessageCarriesTheButtonsForTheCurrentRevision(): void
    {
        $window = new Window(new \DateTimeImmutable('2026-09-27T00:00:00Z'), new \DateTimeImmutable('2026-09-27T23:30:00Z'));
        $edition = [
            'key' => '2026-09-27', 'revision' => 2, 'history' => [['instructions' => 'shorter']],
            'draft' => ['headline' => 'H', 'intro' => 'I', 'sections' => [['title' => 'T', 'body' => 'B']], 'signoff' => ''],
            'reports' => [['source' => 'steam', 'errors' => ['private profile']]],
        ];

        $json = json_encode(Renderer::approval($edition, $window, ['discord-php/DiscordPHP.org', 'valzargaming/valgorithms.com']), JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('newsletter:approve:2026-09-27:2', $json);
        $this->assertStringContainsString('newsletter:edit:2026-09-27:2', $json);
        $this->assertStringContainsString('newsletter:reject:2026-09-27:2', $json);
        $this->assertStringContainsString('private profile', $json);
        $this->assertStringContainsString('shorter', $json);
        $this->assertStringContainsString('also publishes it to discord-php/DiscordPHP.org, valzargaming/valgorithms.com', $json);
    }

    public function testNextAndPreviousRunRespectTheTimeZone(): void
    {
        $tz = new \DateTimeZone('America/New_York');
        $now = new \DateTimeImmutable('2026-09-27T12:00:00-04:00');

        $this->assertSame('2026-09-27 23:30', Scheduler::nextRun($now, '23:30', $tz)->format('Y-m-d H:i'));
        $this->assertSame('2026-09-26 23:30', Scheduler::previousRun($now, '23:30', $tz)->format('Y-m-d H:i'));
        $this->assertSame('2026-09-28 08:00', Scheduler::nextRun($now, '08:00', $tz)->format('Y-m-d H:i'));
    }

    public function testStateStorePersistsEditionsAndHandsOutFreeKeys(): void
    {
        $path = self::tempFile('state');
        $state = new StateStore($path);
        $state->putEdition(['key' => '2026-09-27', 'status' => StateStore::STATUS_PENDING, 'created_at' => '2026-09-27T23:30:00+00:00', 'dm_message_ids' => ['111']]);
        $state->putEdition(['key' => '2026-09-26', 'status' => StateStore::STATUS_POSTED, 'created_at' => '2026-09-26T23:30:00+00:00']);

        $reloaded = new StateStore($path);
        $this->assertSame('2026-09-27-2', $reloaded->freeEditionKey('2026-09-27'));
        $this->assertSame(['2026-09-27'], array_column($reloaded->pendingEditions(), 'key'));
        $this->assertSame('2026-09-27', $reloaded->editionByDmMessage('111')['key']);
        $this->assertNull($reloaded->editionByDmMessage('222'));
    }
}
