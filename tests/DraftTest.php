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

use Newsletter\Draft;

final class DraftTest extends TestCase
{
    public function testParsesJsonWrappedInFencesAndChatter(): void
    {
        $draft = Draft::fromLlmJson("Sure! Here it is:\n```json\n{\"headline\":\"Shipped v10.20\",\"intro\":\"Big day.\",\"sections\":[{\"title\":\"Code\",\"body\":\"Merged two PRs.\"}],\"signoff\":\"Cheers\"}\n```");

        $this->assertSame('Shipped v10.20', $draft->headline);
        $this->assertSame([['title' => 'Code', 'body' => 'Merged two PRs.']], $draft->sections);
        $this->assertStringContainsString('## Code', $draft->toMarkdown());
    }

    public function testDefusesMassMentions(): void
    {
        $draft = Draft::fromArray(['headline' => 'Hi @everyone', 'intro' => '', 'sections' => [['title' => '', 'body' => 'ping @here']]]);

        $this->assertStringNotContainsString('@everyone', $draft->headline);
        $this->assertStringNotContainsString('@here', $draft->sections[0]['body']);
    }

    public function testRejectsDraftsWithoutAHeadlineOrContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Draft::fromArray(['headline' => 'Only a headline', 'intro' => '', 'sections' => []]);
    }

    public function testRejectsNonJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Draft::fromLlmJson('I could not write that.');
    }

    public function testCapsSectionCount(): void
    {
        $sections = array_fill(0, 12, ['title' => 'T', 'body' => 'B']);
        $this->assertCount(Draft::MAX_SECTIONS, Draft::fromArray(['headline' => 'H', 'sections' => $sections])->sections);
    }
}
