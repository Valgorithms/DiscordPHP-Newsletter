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
use Newsletter\Sources\SourceReport;
use Newsletter\Window;
use Newsletter\Writer;

final class WriterTest extends TestCase
{
    private const DRAFT = '{"headline":"Polls land in DiscordPHP","intro":"A productive Sunday.","sections":[{"title":"Code","body":"Merged discord-php/DiscordPHP#1400."}],"signoff":"See you tomorrow!"}';

    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-09-27T00:00:00Z'), new \DateTimeImmutable('2026-09-27T23:30:00Z'));
    }

    /** @return list<SourceReport> */
    private function reports(): array
    {
        return [
            new SourceReport('github', 'GitHub activity', ['[discord-php/DiscordPHP] merged pull request #1400 "Add polls"'], ['commits' => 3]),
            new SourceReport('steam', 'Steam activity'), // empty: no notes call
        ];
    }

    public function testWriteRunsNotesComposeAndFactCheckInOrder(): void
    {
        $writer = new Writer($this->fakeLlm(['- Merged polls (#1400)', self::DRAFT, self::DRAFT]), 'Valithor');

        $written = self::settle($writer->write($this->window(), $this->reports()));

        $this->assertSame('Polls land in DiscordPHP', $written['draft']->headline);
        $this->assertSame(['github' => '- Merged polls (#1400)'], $written['notes']);
        $this->assertCount(3, $this->prompts);
        $this->assertStringContainsString('merged pull request #1400', $this->prompts[0]);
        $this->assertStringContainsString('- Merged polls (#1400)', $this->prompts[1]);
        $this->assertStringContainsString('Check every claim', $this->prompts[2]);
    }

    public function testComposeRetriesOnceThenFallsBackToTheTemplate(): void
    {
        $writer = new Writer($this->fakeLlm(['notes', 'not json', 'still not json']), 'Valithor', factCheck: false);

        $draft = self::settle($writer->write($this->window(), $this->reports()))['draft'];

        $this->assertStringStartsWith("Valithor's daily recap", $draft->headline);
        $this->assertSame('GitHub activity', $draft->sections[0]['title']);
        $this->assertStringContainsString('not usable', $this->prompts[2]);
    }

    public function testUnreachableModelStillProducesADraft(): void
    {
        $down = new \RuntimeException('Connection refused');
        $writer = new Writer($this->fakeLlm([$down, $down, $down]), 'Valithor');

        $written = self::settle($writer->write($this->window(), $this->reports()));

        $this->assertStringContainsString('merged pull request #1400', $written['draft']->sections[0]['body']);
        $this->assertStringContainsString('### GitHub activity', $written['notes']['github'], 'raw facts stand in for notes');
    }

    public function testQuietDayNeedsNoModel(): void
    {
        $writer = new Writer($this->fakeLlm([]), 'Valithor');
        $draft = self::settle($writer->write($this->window(), [new SourceReport('github', 'GitHub')]))['draft'];

        $this->assertStringContainsString('quiet day', $draft->intro);
        $this->assertSame([], $this->prompts);
    }

    public function testFactCheckFailureKeepsTheDraft(): void
    {
        $writer = new Writer($this->fakeLlm(['notes', self::DRAFT, 'garbage']), 'Valithor');

        $this->assertSame('Polls land in DiscordPHP', self::settle($writer->write($this->window(), $this->reports()))['draft']->headline);
    }

    public function testReviseSendsTheInstructionsAndCurrentDraft(): void
    {
        $revised = str_replace('A productive Sunday.', 'Short and sweet.', self::DRAFT);
        $writer = new Writer($this->fakeLlm([$revised]), 'Valithor');

        $draft = self::settle($writer->revise(Draft::fromLlmJson(self::DRAFT), 'Make the intro shorter', ['github' => '- polls']));

        $this->assertSame('Short and sweet.', $draft->intro);
        $this->assertStringContainsString('Make the intro shorter', $this->prompts[0]);
        $this->assertStringContainsString('A productive Sunday.', $this->prompts[0]);
        $this->assertStringContainsString('- polls', $this->prompts[0]);
    }

    public function testReviseRejectsWhenTheModelNeverReturnsADraft(): void
    {
        $writer = new Writer($this->fakeLlm(['nope', 'nope']), 'Valithor');

        $this->expectException(\InvalidArgumentException::class);
        self::settle($writer->revise(Draft::fromLlmJson(self::DRAFT), 'x', []));
    }

    public function testWritesProseFromMarkdownAndSaysWhenItFellBack(): void
    {
        $prose = "# Polls land in DiscordPHP\nI merged the polls work today.\n\n— Cheers";
        $writer = new Writer($this->fakeLlm(['notes', $prose]), 'Valithor', factCheck: false);

        $written = self::settle($writer->write($this->window(), $this->reports()));

        $this->assertNull($written['fallback']);
        $this->assertSame('I merged the polls work today.', $written['draft']->intro);
        $this->assertStringContainsString('Reply with only the newsletter', $this->prompts[1]);

        $failing = new Writer($this->fakeLlm(['notes', new \RuntimeException('LLM server error: model requires more system memory'), 'x']), 'Valithor');
        $this->prompts = [];
        $fellBack = self::settle($failing->write($this->window(), $this->reports()));

        $this->assertSame('LLM server error: model requires more system memory', $fellBack['fallback']);
        $this->assertStringStartsWith("Valithor's daily recap", $fellBack['draft']->headline);
    }
}
