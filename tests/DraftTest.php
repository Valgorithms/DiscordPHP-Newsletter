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

    public function testParsesTheMarkdownTheWriterAsksFor(): void
    {
        $draft = Draft::fromLlmText(<<<'MD'
            Sure! Here's today's newsletter:

            # Three merges and a brand-new website
            Today was mostly about **DiscordPHP**: I spent the morning reviewing community pull requests.

            ## The website
            DiscordPHP.org went live.
            It maps every route.

            ## Gaming
            A quiet hour of Factorio.

            — See you tomorrow!
            MD);

        $this->assertSame('Three merges and a brand-new website', $draft->headline);
        $this->assertStringStartsWith('Today was mostly about **DiscordPHP**', $draft->intro);
        $this->assertSame(['The website', 'Gaming'], array_column($draft->sections, 'title'));
        $this->assertSame("DiscordPHP.org went live.\nIt maps every route.", $draft->sections[0]['body']);
        $this->assertSame('See you tomorrow!', $draft->signoff);
        $this->assertEquals($draft, Draft::fromMarkdown($draft->toMarkdown()), 'toMarkdown() round-trips');
    }

    public function testMarkdownWithoutAHeadingUsesTheFirstLine(): void
    {
        $draft = Draft::fromMarkdown("**A calm Sunday**\n\nI tidied up the docs and merged a small fix.");

        $this->assertSame('A calm Sunday', $draft->headline);
        $this->assertSame('I tidied up the docs and merged a small fix.', $draft->intro);
        $this->assertSame([], $draft->sections);
    }

    public function testMarkdownWithNothingButAHeadlineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Draft::fromMarkdown('# Just a title');
    }
}
