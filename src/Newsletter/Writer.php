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

use Newsletter\Llm\OllamaClient;
use Newsletter\Sources\SourceReport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Everything the local model writes, as a short chain of small prompts
 * (small local models do far better on several focused asks than one big one):
 *
 *  1. {@see notes()}   — per source, turn raw activity into plain-English notes;
 *  2. {@see compose()} — write the newsletter from all notes, as Markdown prose;
 *  3. {@see review()}  — fact-check the draft against the notes and fix it;
 *  4. {@see revise()}  — apply the owner's requested edits, later, on demand.
 *
 * Replies are plain Markdown ({@see Draft::fromMarkdown()}), not JSON: local
 * models write prose far more reliably than they fill in a schema, and some
 * "thinking" models return nothing at all when forced into one.
 *
 * Calls run one after another, never in parallel, so a single local GPU is not
 * asked to juggle several generations at once.
 *
 * @since 1.0.0
 */
final class Writer
{
    private readonly LoggerInterface $logger;

    /**
     * @param string $author    Name the newsletter is about (and signed by).
     * @param string $voice     `first` ("I shipped…") or `third` ("Valithor shipped…").
     * @param string $style     Free-form extra style guidance from the owner.
     * @param int    $words     Rough target length of the whole newsletter.
     * @param bool   $factCheck Whether to run the {@see review()} pass.
     */
    public function __construct(
        private readonly OllamaClient $llm,
        private readonly string $author,
        private readonly string $voice = 'first',
        private readonly string $style = '',
        private readonly int $words = 300,
        private readonly bool $factCheck = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Notes for every non-empty report, then a composed (and optionally
     * fact-checked) draft. `fallback` is why the model could not write it, when
     * the draft is the deterministic template instead; otherwise null.
     *
     * @param list<SourceReport> $reports
     *
     * @return PromiseInterface<array{draft: Draft, notes: array<string, string>, fallback: ?string}>
     */
    public function write(Window $window, array $reports): PromiseInterface
    {
        $notes = [];
        $chain = resolve(null);
        foreach ($reports as $report) {
            if ($report->isEmpty()) {
                continue;
            }
            // Plain closures, not arrow fns: $notes must be shared by reference.
            $chain = $chain->then(function () use (&$notes, $report): PromiseInterface {
                return $this->notes($report)->then(function (string $text) use (&$notes, $report): void {
                    $notes[$report->source] = $text;
                });
            });
        }

        return $chain
            ->then(function () use ($window, $reports, &$notes): PromiseInterface {
                return $this->compose($window, $reports, $notes);
            })
            ->then(function (array $composed) use (&$notes): PromiseInterface {
                if ($composed['fallback'] !== null || ! $this->factCheck || $notes === []) {
                    return resolve($composed);
                }

                return $this->review($composed['draft'], $notes)
                    ->then(static fn(Draft $draft): array => ['draft' => $draft, 'fallback' => null]);
            })
            ->then(function (array $composed) use (&$notes): array {
                return ['draft' => $composed['draft'], 'notes' => $notes, 'fallback' => $composed['fallback']];
            });
    }

    /**
     * Turns one source's raw activity into plain-English notes. On failure the
     * raw facts are used as the notes, so one slow generation cannot sink an edition.
     *
     * @return PromiseInterface<string>
     */
    public function notes(SourceReport $report): PromiseInterface
    {
        $facts = $report->toPromptText();
        $this->logger->info("Asking the model for {$report->source} notes…");

        return $this->llm->chat([
            ['role' => 'system', 'content' => 'You are the research assistant for a personal daily newsletter. You read raw activity logs and explain, in plain English, what the person was actually working on. You never invent facts.'],
            ['role' => 'user', 'content' => <<<PROMPT
                Below is today's raw activity from one source. Write short notes for the newsletter writer that explain what
                was going on, not a copy of the log:
                - group the activity into 2 to 6 themes (for example "reviewing community pull requests about command
                  handling and autocomplete", or "launched the DiscordPHP.org website");
                - for each theme say, in a sentence or two, what the work was about and what came of it (merged, shipped,
                  sent back for changes, still in progress), using the titles and commit messages to understand it;
                - keep the names that matter (projects, servers, games) and at most a few PR numbers for the biggest items;
                - skip routine noise (branch creation, single stars, one-off chatter) unless nothing else happened;
                - never add anything that is not in the log.

                {$facts}
                PROMPT],
        ], null, 0.2)->then(
            static fn(string $text): string => trim($text),
            function (\Throwable $e) use ($report, $facts): string {
                $this->logger->warning("Notes for {$report->source} failed, using raw facts: {$e->getMessage()}");

                return $facts;
            },
        );
    }

    /**
     * Writes the newsletter from the notes. Retries once with the problem fed
     * back; after that, falls back to {@see fallback()} and says why.
     *
     * @param list<SourceReport>    $reports
     * @param array<string, string> $notes   Keyed by source name.
     *
     * @return PromiseInterface<array{draft: Draft, fallback: ?string}>
     */
    public function compose(Window $window, array $reports, array $notes): PromiseInterface
    {
        if ($notes === []) {
            return resolve(['draft' => self::quietDay($window, $this->author), 'fallback' => null]);
        }

        $body = implode("\n\n", array_map(static fn($source, $text) => "## Notes: {$source}\n{$text}", array_keys($notes), $notes));

        $this->logger->info('Asking the model to write the newsletter…');
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => <<<PROMPT
                Write the newsletter for {$window->label()} from these notes.

                {$body}

                {$this->format()}
                PROMPT],
        ];

        return $this->draftFrom($messages, 0.6)->then(
            static fn(Draft $draft): array => ['draft' => $draft, 'fallback' => null],
            function (\Throwable $e) use ($window, $reports, $notes): array {
                $this->logger->warning("The model could not write the newsletter, using the template draft: {$e->getMessage()}");

                return ['draft' => self::fallback($window, $reports, $notes, $this->author), 'fallback' => $e->getMessage()];
            },
        );
    }

    /**
     * Fact-checks `$draft` against the notes and returns a corrected draft. Any
     * failure keeps the original: a missed check is better than a lost draft.
     *
     * @param array<string, string> $notes
     *
     * @return PromiseInterface<Draft>
     */
    public function review(Draft $draft, array $notes): PromiseInterface
    {
        $facts = implode("\n\n", array_map(static fn($source, $text) => "## {$source}\n{$text}", array_keys($notes), $notes));
        $this->logger->info('Asking the model to fact-check the draft…');

        return $this->draftFrom([
            ['role' => 'system', 'content' => 'You are a careful fact-checking editor. You only change what is wrong.'],
            ['role' => 'user', 'content' => <<<PROMPT
                Here are the only facts that are true:

                {$facts}

                Here is a newsletter draft:

                {$draft->toMarkdown()}

                Check every claim in the draft against the facts. Remove or correct anything that is not supported (invented
                numbers, features, outcomes, people or opinions attributed to others). Keep the tone, structure and length otherwise.
                Reply with ONLY the corrected newsletter, in exactly the same Markdown format.
                PROMPT],
        ], 0.1, false)->then(null, function (\Throwable $e) use ($draft): Draft {
            $this->logger->warning("Fact-check pass failed, keeping the draft: {$e->getMessage()}");

            return $draft;
        });
    }

    /**
     * Applies the owner's requested edits to `$draft`.
     *
     * @param array<string, string> $notes The facts the edition was written from.
     *
     * @return PromiseInterface<Draft> Rejects when the model cannot produce a valid draft.
     */
    public function revise(Draft $draft, string $instructions, array $notes): PromiseInterface
    {
        $facts = implode("\n\n", array_map(static fn($source, $text) => "## {$source}\n{$text}", array_keys($notes), $notes)) ?: '(no facts recorded)';

        return $this->draftFrom([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => <<<PROMPT
                The facts this newsletter was written from:

                {$facts}

                The current draft:

                {$draft->toMarkdown()}

                {$this->author} reviewed it and asked for these changes:

                """{$instructions}"""

                Apply exactly those changes and nothing else. If they ask you to add a fact that is not in the facts above,
                use their wording for it (they are the author and know what they did). Keep everything they did not mention.

                {$this->format()}
                PROMPT],
        ], 0.4);
    }

    /**
     * A deterministic draft built straight from the reports, for when the model
     * is unreachable or keeps returning unusable output.
     *
     * @param list<SourceReport>    $reports
     * @param array<string, string> $notes
     */
    public static function fallback(Window $window, array $reports, array $notes, string $author): Draft
    {
        $sections = [];
        foreach ($reports as $report) {
            if ($report->isEmpty()) {
                continue;
            }
            $lines = array_map(static fn($h) => "- {$h}", array_slice($report->highlights, 0, 12));
            if (count($report->highlights) > 12) {
                $lines[] = '- …and ' . (count($report->highlights) - 12) . ' more';
            }
            if ($lines === [] && $report->stats) {
                $lines[] = implode(', ', array_map(static fn($k, $v) => str_replace('_', ' ', (string) $k) . ": {$v}", array_keys($report->stats), $report->stats));
            }
            $sections[] = ['title' => $report->title, 'body' => implode("\n", $lines)];
        }
        if ($sections === []) {
            return self::quietDay($window, $author);
        }

        return new Draft("{$author}'s daily recap — {$window->label()}", 'Here is what happened today.', $sections, '');
    }

    private static function quietDay(Window $window, string $author): Draft
    {
        return new Draft("{$author}'s daily recap — {$window->label()}", 'A quiet day: nothing to report from GitHub, Discord or Steam.', [], '');
    }

    /**
     * Sends `$messages` expecting a Markdown newsletter; on an unusable reply,
     * retries once with the problem appended (when `$retry`).
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return PromiseInterface<Draft>
     */
    private function draftFrom(array $messages, float $temperature, bool $retry = true): PromiseInterface
    {
        return $this->llm->chat($messages, null, $temperature)->then(
            function (string $reply) use ($messages, $temperature, $retry): PromiseInterface {
                try {
                    return resolve(Draft::fromLlmText($reply));
                } catch (\InvalidArgumentException $e) {
                    if (! $retry) {
                        return reject($e);
                    }
                    $this->logger->notice("The model's newsletter was not usable ({$e->getMessage()}), asking again");
                    $messages[] = ['role' => 'assistant', 'content' => $reply];
                    $messages[] = ['role' => 'user', 'content' => "That was not usable ({$e->getMessage()}). Reply with only the newsletter: a line starting with \"# \" for the headline, then the paragraphs."];

                    return $this->draftFrom($messages, $temperature, false);
                }
            },
        );
    }

    /** The reply format shared by compose and revise, which {@see Draft::fromMarkdown()} reads. */
    private function format(): string
    {
        return <<<FORMAT
            Reply with only the newsletter, in this Markdown format and nothing before or after it:

            # A short headline specific to today
            One or two opening paragraphs.

            ## A section title (optional: only when the day had clearly separate parts, at most 3)
            One or two paragraphs.

            — A one-line sign-off
            FORMAT;
    }

    private function systemPrompt(): string
    {
        $voice = $this->voice === 'third'
            ? "Write in the third person about {$this->author}."
            : "Write in the first person as {$this->author} (\"I\", \"my\").";
        $style = $this->style !== '' ? "\nExtra style guidance from {$this->author}: {$this->style}" : '';

        return <<<PROMPT
            You write {$this->author}'s daily newsletter: a friendly, plain-English recap of the day, posted for their community.
            {$voice} It should read like a short letter to readers, not a changelog.
            Rules:
            - Write in flowing paragraphs. Do not use bullet lists.
            - Summarise what the day's work was about and why it matters: the themes, what shipped, what is in progress.
              Never go through pull requests, commits or messages one by one; mention at most two or three by name or
              number when they were the day's highlights (refer to them as owner/repo#123).
            - Use only the facts you are given. Never invent numbers, features, results, quotes or people.
            - Leave out anything that happened but does not matter to a reader.
            - Discord markdown is fine for emphasis (**bold**, *italics*, `code`). No tables, no HTML, no @everyone/@here, no emoji spam.
            - About {$this->words} words in total. The headline is short and specific to today, never just "Daily recap".{$style}
            PROMPT;
    }
}
