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

    public function testReadsTheEarlierEditionsBeforeWritingAndChecking(): void
    {
        $previous = [['key' => '2026-09-26', 'date' => 'Saturday, September 26, 2026', 'markdown' => "# Caches get smarter\nI started moving the parts into route namespaces.\n\n— Until tomorrow"]];
        $writer = new Writer($this->fakeLlm(['- Finished the namespace move', self::DRAFT, self::DRAFT]), 'Valithor');

        self::settle($writer->write($this->window(), $this->reports(), $previous));

        $this->assertStringNotContainsString('Earlier editions', $this->prompts[0], 'notes describe today only');
        foreach ([1, 2] as $i) {
            $this->assertStringContainsString('=== Published Saturday, September 26, 2026 ===', $this->prompts[$i]);
            $this->assertStringContainsString('Headline: Caches get smarter', $this->prompts[$i]);
            $this->assertStringNotContainsString('# Caches get smarter', $this->prompts[$i], 'their headings are demoted');
        }
        $this->assertStringContainsString('Do not reuse their headlines', $this->prompts[1]);
        $this->assertStringContainsString('Do not announce again', $this->prompts[1]);
        $this->assertStringContainsString('must not be presented as today', (string) preg_replace('/\s+/', ' ', $this->prompts[2]));

        $this->prompts = [];
        self::settle((new Writer($this->fakeLlm(['notes', self::DRAFT]), 'Valithor', factCheck: false))->write($this->window(), $this->reports()));
        $this->assertStringNotContainsString('Earlier editions', $this->prompts[1], 'nothing to say before the first edition');

        $this->prompts = [];
        self::settle((new Writer($this->fakeLlm([self::DRAFT]), 'Valithor'))->revise(Draft::fromLlmJson(self::DRAFT), 'Shorter', [], $previous));
        $this->assertStringContainsString('Caches get smarter', $this->prompts[0]);
    }

    public function testNeverReportsTheNewsletterItselfAsWork(): void
    {
        $writer = new Writer($this->fakeLlm(['notes', self::DRAFT, self::DRAFT]), 'Valithor');

        self::settle($writer->write($this->window(), $this->reports()));

        $this->assertStringContainsString('the newsletter itself being written, posted or published is not work', $this->prompts[0]);
        $this->assertStringContainsString("this newsletter's own bot", $this->prompts[0]);
        $this->assertStringContainsString('Remove any', $this->prompts[2]);
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

    public function testFactCheckerUsesOriginalReportsInsteadOfInventedModelNotes(): void
    {
        $invented = 'Released version 99.0 with complete coverage of every API.';
        $writer = new Writer($this->fakeLlm([$invented, self::DRAFT, self::DRAFT]), 'Valithor');

        self::settle($writer->write($this->window(), $this->reports()));

        $this->assertStringContainsString($invented, $this->prompts[1], 'composition uses the derived notes');
        $this->assertStringContainsString('merged pull request #1400 "Add polls"', $this->prompts[2]);
        $this->assertStringNotContainsString($invented, $this->prompts[2], 'the checker must not accept notes as evidence');
        $this->assertStringContainsString('Original source evidence', $this->prompts[2]);
    }

    public function testFailedEmptySourcesDoNotBecomeAQuietDay(): void
    {
        $writer = new Writer($this->fakeLlm([]), 'Valithor');
        $reports = [SourceReport::failed('github', 'GitHub', 'connection refused'), new SourceReport('steam', 'Steam')];

        $written = self::settle($writer->write($this->window(), $reports));

        $this->assertSame([], $this->prompts, 'no model should invent activity when evidence is unavailable');
        $this->assertStringContainsString('incomplete', $written['draft']->headline);
        $this->assertStringContainsString('could not be read', $written['draft']->intro);
        $this->assertStringNotContainsString('quiet day', $written['draft']->intro);
        $this->assertStringNotContainsString('connection refused', $written['draft']->toMarkdown());
        $this->assertNull($written['fallback'], 'source failure is distinct from model failure');
        $this->assertStringContainsString('incomplete', Writer::fallback($this->window(), $reports, [], 'Valithor')->headline);
    }

    public function testPartialSourceFailuresReachCompositionAndReview(): void
    {
        $reports = [$this->reports()[0], SourceReport::failed('steam', 'Steam', 'private transport diagnostic')];
        $writer = new Writer($this->fakeLlm(['Merged polls', self::DRAFT, self::DRAFT]), 'Valithor');

        self::settle($writer->write($this->window(), $reports));

        $this->assertCount(3, $this->prompts, 'failed source has no notes call');
        $this->assertStringContainsString('Data coverage: steam reported incomplete data', $this->prompts[1]);
        $this->assertStringContainsString($this->window()->range(), $this->prompts[1]);
        $this->assertStringNotContainsString('private transport diagnostic', $this->prompts[1]);
        $this->assertStringContainsString('activity data unavailable', $this->prompts[2]);
        $fallback = Writer::fallback($this->window(), $reports, [], 'Valithor');
        $this->assertStringContainsString('Some sources could not be read', $fallback->intro);
        $this->assertStringNotContainsString('private transport diagnostic', $fallback->toMarkdown());
    }

    public function testRevisionUsesOriginalReportsAndRetainsOwnerInstructions(): void
    {
        $writer = new Writer($this->fakeLlm([self::DRAFT]), 'Valithor');

        self::settle($writer->revise(Draft::fromLlmJson(self::DRAFT), 'Add that I am on vacation.', ['github' => 'Invented version 99.0 release'], [], $this->reports()));

        $this->assertStringContainsString('merged pull request #1400 "Add polls"', $this->prompts[0]);
        $this->assertStringNotContainsString('Invented version 99.0 release', $this->prompts[0]);
        $this->assertStringContainsString('Add that I am on vacation.', $this->prompts[0]);
        $this->assertStringContainsString('use their wording', $this->prompts[0], 'owner-supplied facts remain trusted');
    }

    public function testLegacyReviewAndRevisionKeepSavedNotes(): void
    {
        $writer = new Writer($this->fakeLlm([self::DRAFT, self::DRAFT]), 'Valithor');
        $draft = Draft::fromLlmJson(self::DRAFT);
        self::settle($writer->review($draft, ['github' => 'Saved original notes']));
        self::settle($writer->revise($draft, 'Shorter', ['github' => 'Saved original notes']));

        foreach ($this->prompts as $prompt) {
            $this->assertStringContainsString('Original reports unavailable', $prompt);
            $this->assertStringContainsString('Saved original notes', $prompt);
        }
    }

    public function testEditorialGuidanceDistinguishesNewProgressAndActualOutcomes(): void
    {
        $previous = [['date' => 'Yesterday', 'markdown' => "# Introducing Frontier Systems\nI introduced the cooperative engineering project."]];
        $writer = new Writer($this->fakeLlm([self::DRAFT]), 'Valithor');
        self::settle($writer->compose($this->window(), $this->reports(), ['github' => 'An inventory fix is open for review.'], $previous));
        $system = $this->llmMessages[0][0]['content'];

        $this->assertStringContainsString('Lead with a concrete result', $system);
        $this->assertStringContainsString('Merge duplicate coverage', $system);
        $this->assertStringContainsString('merged code is not proof of deployment', $system);
        $this->assertStringContainsString('Never pad the draft', $system);
        $this->assertStringContainsString('Describe open work as intended behavior', $system);
        $this->assertStringContainsString('A previously introduced project is not a new announcement', $this->prompts[0]);
    }
}
