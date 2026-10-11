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

use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * One draft per approved edition and collective. An uncertain create is never
 * retried automatically: the marker survives a failed response or restart.
 */
final class OpenCollectiveDrafts
{
    /** @var callable(array<string, mixed>): PromiseInterface<Draft> */
    private $generate;
    /** @var array<string, true> */
    private array $busy = [];

    /** @param callable(array<string, mixed>): PromiseInterface<Draft> $generate */
    public function __construct(
        private readonly StateStore $state,
        private readonly OpenCollectivePublisher $publisher,
        callable $generate,
    ) {
        $this->generate = $generate;
    }

    /** @return PromiseInterface<array<string, mixed>> */
    public function create(string $key): PromiseInterface
    {
        $edition = $this->state->edition($key);
        if ($edition === null || ($edition['status'] ?? null) !== StateStore::STATUS_POSTED) {
            return reject(new \RuntimeException('Only a posted, owner-approved newsletter can become a supporter update.'));
        }
        $slug = $this->publisher->collective;
        $saved = $edition['collective_drafts'][$slug] ?? [];
        if (isset($saved['id'])) {
            return resolve($saved);
        }
        if (($saved['status'] ?? '') === 'creation_started') {
            return reject(new \RuntimeException('An earlier draft creation may have succeeded. Check Open Collective before clearing its saved marker; no automatic retry was made.'));
        }
        if (isset($this->busy[$key])) {
            return reject(new \RuntimeException('That supporter update is already being prepared.'));
        }
        $this->busy[$key] = true;

        return resolve(null)->then(fn(): PromiseInterface => ($this->generate)($edition))
            ->then(function (Draft $draft) use ($key, $slug): PromiseInterface {
                $current = $this->state->edition($key);
                if ($current === null || ($current['status'] ?? null) !== StateStore::STATUS_POSTED) {
                    throw new \RuntimeException('The source edition is no longer approved.');
                }
                $current['collective_drafts'][$slug] = [
                    'status' => 'creation_started',
                    'draft' => $draft->toArray(),
                    'created_at' => date(DATE_ATOM),
                ];
                // Persist before the mutation, so losing its response cannot create duplicate drafts.
                $this->state->putEdition($current);
                return $this->publisher->createDraft($draft);
            })->then(function (array $created) use ($key, $slug): array {
                $current = $this->state->edition($key) ?? throw new \RuntimeException('The source edition disappeared.');
                $saved = $current['collective_drafts'][$slug];
                $saved = [...$saved, ...$created, 'status' => 'draft'];
                $current['collective_drafts'][$slug] = $saved;
                $this->state->putEdition($current);
                return $saved;
            })->finally(function () use ($key): void {
                unset($this->busy[$key]);
            });
    }
}
