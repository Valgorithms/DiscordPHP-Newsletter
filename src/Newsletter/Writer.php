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
 *  1. {@see notes()}   — per source, condense raw facts into editorial notes;
 *  2. {@see compose()} — write the newsletter from all notes as {@see Draft} JSON;
 *  3. {@see review()}  — fact-check the draft against the notes and fix it;
 *  4. {@see revise()}  — apply the owner's requested edits, later, on demand.
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
        private readonly int $words = 350,
        private readonly bool $factCheck = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Notes for every non-empty report, then a composed (and optionally
     * fact-checked) draft.
     *
     * @param list<SourceReport> $reports
     *
     * @return PromiseInterface<array{draft: Draft, notes: array<string, string>}>
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
            ->then(function (Draft $draft) use (&$notes): PromiseInterface {
                return $this->factCheck && $notes !== [] ? $this->review($draft, $notes) : resolve($draft);
            })
            ->then(static fn(Draft $draft) => ['draft' => $draft, 'notes' => $notes]);
    }

    /**
     * Condenses one source's facts into editorial notes. On failure the raw
     * facts are used as the notes, so one slow generation cannot sink an edition.
     *
     * @return PromiseInterface<string>
     */
    public function notes(SourceReport $report): PromiseInterface
    {
        $facts = $report->toPromptText();

        return $this->llm->chat([
            ['role' => 'system', 'content' => 'You are the research assistant for a personal daily newsletter. You turn raw activity logs into accurate editorial notes. You never invent facts.'],
            ['role' => 'user', 'content' => <<<PROMPT
                Below are today's raw facts from one source. Write editorial notes for the newsletter writer:
                - 3 to 12 short bullet points, most significant first;
                - group related items (same repository, same server, same game) into one bullet;
                - keep concrete names, numbers, PR/issue numbers, game titles and server names;
                - say what was accomplished or what it means, where the facts make that clear;
                - leave out trivia (single stars, tiny chatter) unless nothing else happened;
                - never add anything that is not in the facts.
                Reply with the bullet points only.

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
     * Writes the newsletter from the notes. Retries once with the parse error
     * fed back; after that, falls back to {@see fallback()}.
     *
     * @param list<SourceReport>    $reports
     * @param array<string, string> $notes   Keyed by source name.
     *
     * @return PromiseInterface<Draft>
     */
    public function compose(Window $window, array $reports, array $notes): PromiseInterface
    {
        if ($notes === []) {
            return resolve(self::quietDay($window, $this->author));
        }

        $stats = implode("\n", array_map(
            static fn(SourceReport $r) => "- {$r->title}: " . ($r->stats ? json_encode($r->stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'none'),
            $reports,
        ));
        $body = implode("\n\n", array_map(static fn($source, $text) => "## Notes: {$source}\n{$text}", array_keys($notes), $notes));

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => <<<PROMPT
                Write the newsletter for {$window->label()} (covering {$window->range()}).

                Headline numbers:
                {$stats}

                {$body}

                Reply with ONLY a JSON object: {"headline": string, "intro": string, "sections": [{"title": string, "body": string}], "signoff": string}.
                Use one section per area of activity that actually had something happen (for example code, community, gaming), at most 6 sections.
                PROMPT],
        ];

        return $this->draftFrom($messages, 0.6)->then(null, function (\Throwable $e) use ($window, $reports, $notes): Draft {
            $this->logger->warning("Compose failed, using the template draft: {$e->getMessage()}");

            return self::fallback($window, $reports, $notes, $this->author);
        });
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
        $current = json_encode($draft->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $facts = implode("\n\n", array_map(static fn($source, $text) => "## {$source}\n{$text}", array_keys($notes), $notes));

        return $this->draftFrom([
            ['role' => 'system', 'content' => 'You are a careful fact-checking editor. You only change what is wrong.'],
            ['role' => 'user', 'content' => <<<PROMPT
                Here are the only facts that are true:

                {$facts}

                Here is a newsletter draft as JSON:

                {$current}

                Check every claim in the draft against the facts. Remove or correct anything that is not supported (invented
                numbers, features, outcomes, people or opinions attributed to others). Keep the tone, structure and length otherwise.
                Reply with ONLY the corrected JSON object in the same shape.
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
        $current = json_encode($draft->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $facts = implode("\n\n", array_map(static fn($source, $text) => "## {$source}\n{$text}", array_keys($notes), $notes)) ?: '(no facts recorded)';

        return $this->draftFrom([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => <<<PROMPT
                The facts this newsletter was written from:

                {$facts}

                The current draft as JSON:

                {$current}

                {$this->author} reviewed it and asked for these changes:

                """{$instructions}"""

                Apply exactly those changes and nothing else. If they ask you to add a fact that is not in the facts above,
                use their wording for it (they are the author and know what they did). Keep everything they did not mention.
                Reply with ONLY the revised JSON object in the same shape.
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
     * Sends `$messages` expecting draft JSON; on a parse failure, retries once
     * with the error appended (when `$retry`).
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return PromiseInterface<Draft>
     */
    private function draftFrom(array $messages, float $temperature, bool $retry = true): PromiseInterface
    {
        return $this->llm->chat($messages, Draft::SCHEMA, $temperature)->then(
            function (string $reply) use ($messages, $temperature, $retry): PromiseInterface {
                try {
                    return resolve(Draft::fromLlmJson($reply));
                } catch (\InvalidArgumentException $e) {
                    if (! $retry) {
                        return reject($e);
                    }
                    $this->logger->notice("Draft JSON unusable ({$e->getMessage()}), asking again");
                    $messages[] = ['role' => 'assistant', 'content' => $reply];
                    $messages[] = ['role' => 'user', 'content' => "That was not usable ({$e->getMessage()}). Reply with ONLY the JSON object, with a non-empty headline and at least one section."];

                    return $this->draftFrom($messages, $temperature, false);
                }
            },
        );
    }

    private function systemPrompt(): string
    {
        $voice = $this->voice === 'third'
            ? "Write in the third person about {$this->author}."
            : "Write in the first person as {$this->author} (\"I\", \"my\").";
        $style = $this->style !== '' ? "\nExtra style guidance from {$this->author}: {$this->style}" : '';

        return <<<PROMPT
            You write {$this->author}'s daily newsletter: a friendly, upbeat recap of the day's work and play, posted in their Discord server
            for their community. {$voice}
            Rules:
            - Use only the facts you are given. Never invent numbers, features, results, quotes or people.
            - Lead with the most meaningful work. Group related items; do not list every commit or message.
            - Discord markdown is fine (**bold**, *italics*, `code`, bullet lists). No tables, no HTML, no @everyone/@here, no emoji spam.
            - Refer to GitHub items as owner/repo#123 so readers can find them.
            - Aim for about {$this->words} words in total; each section body is 1 to 5 sentences or a short bullet list.
            - Headline: short and specific to today (not just "Daily recap"). Sign-off: one short line.{$style}
            PROMPT;
    }
}
