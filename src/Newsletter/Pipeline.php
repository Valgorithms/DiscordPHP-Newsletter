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

use Newsletter\Sources\Source;
use Newsletter\Sources\SourceReport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;

use function React\Promise\all;

/**
 * Collect → write → store: turns a {@see Window} into a stored edition that is
 * ready to be sent for approval.
 *
 * An edition is a plain array persisted in the {@see StateStore}:
 * `key`, `window`, `status`, `revision`, `draft`, `notes` (the facts revisions
 * are checked against), `reports`, `history` (requested edits), `dm_message_ids`,
 * `posted_message_id`, `created_at`, `manual`.
 *
 * @since 1.0.0
 */
final class Pipeline
{
    private readonly LoggerInterface $logger;

    /** @param list<Source> $sources */
    public function __construct(
        private readonly array $sources,
        private readonly Writer $writer,
        private readonly StateStore $state,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Gathers every source in parallel. A source that rejects becomes a report
     * carrying the error, so one broken API never sinks the edition.
     *
     * @return PromiseInterface<list<SourceReport>>
     */
    public function collect(Window $window): PromiseInterface
    {
        return all(array_map(fn(Source $source): PromiseInterface => $source->collect($window)->then(
            null,
            function (\Throwable $e) use ($source): SourceReport {
                $this->logger->warning("Source {$source->name()} failed: {$e->getMessage()}");

                return SourceReport::failed($source->name(), ucfirst($source->name()) . ' activity', $e->getMessage());
            },
        ), $this->sources))->then(static fn(array $reports): array => array_values($reports));
    }

    /**
     * Collects, writes and stores a new edition for `$window`.
     *
     * @return PromiseInterface<array<string, mixed>> The stored edition.
     */
    public function run(Window $window, bool $manual = false): PromiseInterface
    {
        $this->logger->info("Building the newsletter for {$window->range()}");

        return $this->collect($window)
            ->then(fn(array $reports): PromiseInterface => $this->writer->write($window, $reports)
                ->then(function (array $written) use ($window, $reports, $manual): array {
                    $edition = [
                        'key' => $this->state->freeEditionKey($window->key()),
                        'window' => $window->toArray(),
                        'status' => StateStore::STATUS_DRAFTING,
                        'revision' => 1,
                        'draft' => $written['draft']->toArray(),
                        'notes' => $written['notes'],
                        'reports' => array_map(static fn(SourceReport $r) => $r->toArray(), $reports),
                        'history' => [],
                        'dm_message_ids' => [],
                        'posted_message_id' => null,
                        'created_at' => date(DATE_ATOM),
                        'manual' => $manual,
                    ];
                    $this->state->putEdition($edition);
                    $this->logger->info("Edition {$edition['key']} drafted");

                    return $edition;
                }));
    }
}
