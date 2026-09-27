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

namespace Newsletter\Bot;

use Discord\Builders\Components\Label;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Builders\ModalBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;
use Newsletter\Draft;
use Newsletter\Pipeline;
use Newsletter\ReplyInterpreter;
use Newsletter\StateStore;
use Newsletter\Window;
use Newsletter\Writer;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The owner-in-the-loop part: every edition is DM'd to the owner first, and
 * only an explicit approval posts it to the newsletter channel.
 *
 * The owner can answer a draft three ways:
 *  - the buttons under it (Approve & post / Request edits / Skip);
 *  - a DM reply in plain words ("drop the Steam bit and mention the release"),
 *    which the local model classifies and, for edits, applies before sending a
 *    new revision for approval;
 *  - DM commands: `!generate`, `!status`, `!help`.
 *
 * All state lives in the {@see StateStore}, and button ids carry the edition
 * key and revision, so drafts stay actionable across restarts and a stale
 * revision's buttons cannot post an outdated draft.
 *
 * @since 1.0.0
 */
final class ApprovalFlow
{
    /** @var array<string, true> Editions with an action in flight (double-click guard). */
    private array $busy = [];

    public function __construct(
        private readonly Discord $discord,
        private readonly StateStore $state,
        private readonly Pipeline $pipeline,
        private readonly Writer $writer,
        private readonly ReplyInterpreter $interpreter,
        private readonly string $ownerId,
        private readonly string $channelId,
        private readonly \DateTimeZone $tz,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(): void
    {
        $this->discord->on(Event::INTERACTION_CREATE, fn(Interaction $i) => $this->onInteraction($i));
        $this->discord->on(Event::MESSAGE_CREATE, fn(Message $m) => $this->onMessage($m));
    }

    /**
     * Builds an edition for `$window` and DMs it to the owner.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function generate(Window $window, bool $manual = false): PromiseInterface
    {
        return $this->pipeline->run($window, $manual)
            ->then(fn(array $edition) => $this->sendForApproval($edition));
    }

    /**
     * DMs the edition's current revision to the owner and marks it pending.
     *
     * @param array<string, mixed> $edition
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function sendForApproval(array $edition): PromiseInterface
    {
        return $this->dm(Renderer::approval($edition, $this->window($edition)))
            ->then(function (Message $message) use ($edition): array {
                $edition = $this->state->edition($edition['key']) ?? $edition;
                $edition['status'] = StateStore::STATUS_PENDING;
                $edition['dm_message_ids'][] = $message->id;
                $this->state->putEdition($edition);
                $this->logger->info("Edition {$edition['key']} r{$edition['revision']} sent for approval");

                return $edition;
            });
    }

    // --- owner actions --------------------------------------------------------

    /** @return PromiseInterface<Message> */
    public function approve(string $key): PromiseInterface
    {
        return $this->exclusive($key, function (array $edition): PromiseInterface {
            $channel = $this->discord->getChannel($this->channelId);
            if ($channel === null) {
                return reject(new \RuntimeException("Newsletter channel {$this->channelId} is not visible to the bot."));
            }

            return $channel->sendMessage(Renderer::newsletter(Draft::fromArray($edition['draft']), $this->window($edition)))
                ->then(function (Message $posted) use ($edition): Message {
                    $edition['status'] = StateStore::STATUS_POSTED;
                    $edition['posted_message_id'] = $posted->id;
                    $edition['posted_url'] = $posted->link;
                    $this->state->putEdition($edition);
                    $this->dm("✅ Posted the {$edition['key']} newsletter: {$posted->link}");
                    $this->logger->info("Edition {$edition['key']} posted");

                    return $posted;
                });
        });
    }

    /** @return PromiseInterface<mixed> */
    public function reject(string $key): PromiseInterface
    {
        return $this->exclusive($key, function (array $edition): PromiseInterface {
            $edition['status'] = StateStore::STATUS_REJECTED;
            $this->state->putEdition($edition);

            return $this->dm("🗑️ Skipped the {$edition['key']} newsletter. Nothing was posted.");
        });
    }

    /**
     * Has the local model apply `$instructions`, then sends the new revision for
     * approval. On failure the current revision stays pending.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function revise(string $key, string $instructions): PromiseInterface
    {
        return $this->exclusive($key, function (array $edition) use ($instructions): PromiseInterface {
            $edition['status'] = StateStore::STATUS_REVISING;
            $edition['history'][] = ['at' => date(DATE_ATOM), 'instructions' => $instructions];
            $this->state->putEdition($edition);
            $this->dm("✏️ Revising the {$edition['key']} draft with your notes… (the local model can take a minute)");

            return $this->writer->revise(Draft::fromArray($edition['draft']), $instructions, (array) $edition['notes'])
                ->then(function (Draft $draft) use ($edition): PromiseInterface {
                    $edition['draft'] = $draft->toArray();
                    $edition['revision']++;
                    $this->state->putEdition($edition);

                    return $this->sendForApproval($edition);
                }, function (\Throwable $e) use ($edition): PromiseInterface {
                    $edition['status'] = StateStore::STATUS_PENDING;
                    $this->state->putEdition($edition);
                    $this->dm("⚠️ I couldn't apply those edits ({$e->getMessage()}). Revision {$edition['revision']} is still waiting: reply again, or approve it as is.");

                    return reject($e);
                });
        }, [StateStore::STATUS_PENDING]);
    }

    // --- gateway handlers ---------------------------------------------------------

    private function onInteraction(Interaction $interaction): void
    {
        if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT
            || ! ($parsed = Renderer::parseCustomId((string) $interaction->data?->custom_id))) {
            return;
        }
        if ($interaction->user?->id !== $this->ownerId) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Only the newsletter owner can do that.'), true);

            return;
        }

        $edition = $this->state->edition($parsed['key']);
        if ($edition === null || $edition['revision'] !== $parsed['revision'] || $edition['status'] !== StateStore::STATUS_PENDING) {
            $status = $edition === null ? 'no longer exists' : ($edition['revision'] !== $parsed['revision'] ? "has a newer revision ({$edition['revision']})" : "is {$edition['status']}");
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("That draft {$status}."), true);

            return;
        }

        match ($parsed['action']) {
            'approve' => $interaction->acknowledge()->then(fn() => $this->approve($parsed['key']))->then(null, fn(\Throwable $e) => $this->fail('post', $e)),
            'reject' => $interaction->acknowledge()->then(fn() => $this->reject($parsed['key']))->then(null, fn(\Throwable $e) => $this->fail('skip', $e)),
            'edit' => $this->askForEdits($interaction, $parsed['key']),
        };
    }

    private function askForEdits(Interaction $interaction, string $key): void
    {
        $input = TextInput::new(null, TextInput::STYLE_PARAGRAPH, 'instructions')
            ->setPlaceholder('e.g. Shorter intro, drop the Steam section, mention that v10.20 shipped.')
            ->setMaxLength(2000)
            ->setRequired(true);
        $modal = ModalBuilder::new('Edit the newsletter', "newsletter-edit:{$key}", [Label::new('What should change?', $input)]);

        $interaction->respondWithModal($modal, function (Interaction $submit, $components) use ($key): void {
            $instructions = trim((string) ($components->get('custom_id', 'instructions')?->value ?? ''));
            $submit->acknowledge();
            if ($instructions !== '') {
                $this->revise($key, $instructions)->then(null, fn(\Throwable $e) => $this->logger->warning("Revision failed: {$e->getMessage()}"));
            }
        });
    }

    private function onMessage(Message $message): void
    {
        if ($message->guild_id !== null || $message->author?->id !== $this->ownerId) {
            return; // only the owner's DMs
        }
        $content = trim((string) $message->content);
        if ($content === '') {
            return;
        }
        if (str_starts_with($content, '!')) {
            $this->command($message, strtolower(strtok(substr($content, 1), ' ') ?: ''));

            return;
        }

        $referenced = $message->message_reference?->message_id;
        $edition = ($referenced ? $this->state->editionByDmMessage((string) $referenced) : null)
            ?? ($this->state->pendingEditions()[0] ?? null);
        if ($edition === null) {
            $message->reply('No newsletter draft is waiting on you. Send `!generate` to draft one for today so far.');

            return;
        }
        if ($edition['status'] === StateStore::STATUS_REVISING) {
            $message->reply('Still working on the last round of edits for that draft; hang on a moment.');

            return;
        }
        if ($edition['status'] !== StateStore::STATUS_PENDING) {
            $message->reply("The {$edition['key']} newsletter is already {$edition['status']}.");

            return;
        }

        $this->interpreter->interpret($content)->then(function (array $reply) use ($message, $edition): void {
            $key = $edition['key'];
            match ($reply['action']) {
                // Only an unambiguous "approve" posts; an approval the model merely inferred asks first.
                ReplyInterpreter::APPROVE => $reply['via'] === 'quick'
                    ? $this->approve($key)->then(null, fn(\Throwable $e) => $this->fail('post', $e))
                    : $message->reply('Sounds like a yes. Reply `approve` (or press ✅ on the draft) and I\'ll post it.'),
                ReplyInterpreter::REJECT => $this->reject($key)->then(null, fn(\Throwable $e) => $this->fail('skip', $e)),
                default => $this->revise($key, $reply['instructions'])->then(null, fn(\Throwable $e) => $this->logger->warning("Revision failed: {$e->getMessage()}")),
            };
        });
    }

    private function command(Message $message, string $command): void
    {
        switch ($command) {
            case 'generate':
            case 'preview':
                $now = new \DateTimeImmutable('now', $this->tz);
                $message->reply('🛠️ Collecting today\'s activity and drafting with the local model…');
                // A manual run covers today so far and leaves the scheduled window alone.
                $this->generate(Window::since(null, $now), true)
                    ->then(null, fn(\Throwable $e) => $this->fail('generate', $e));
                break;

            case 'status':
                $pending = $this->state->pendingEditions();
                $lines = array_map(static fn($e) => "• **{$e['key']}**: {$e['status']}, revision {$e['revision']}", $pending);
                $message->reply($lines ? "Waiting on you:\n" . implode("\n", $lines) : 'Nothing is waiting on you.');
                break;

            default:
                $message->reply(implode("\n", [
                    '**Newsletter bot**',
                    'Reply to a draft in plain words to request edits, or say `approve` / `skip`.',
                    '`!generate`: draft a newsletter for today so far',
                    '`!status`: list drafts waiting on you',
                ]));
        }
    }

    // --- helpers ---------------------------------------------------------------------

    /**
     * Runs `$action` on a pending edition, one action per edition at a time.
     *
     * @param callable(array<string, mixed>): PromiseInterface $action
     * @param list<string>                                     $allowed Statuses the action may run from.
     */
    private function exclusive(string $key, callable $action, array $allowed = [StateStore::STATUS_PENDING]): PromiseInterface
    {
        $edition = $this->state->edition($key);
        if ($edition === null || ! in_array($edition['status'], $allowed, true)) {
            return reject(new \RuntimeException("Edition {$key} is not waiting for that."));
        }
        if (isset($this->busy[$key])) {
            return reject(new \RuntimeException("Edition {$key} is already being handled."));
        }
        $this->busy[$key] = true;

        return $action($edition)->finally(function () use ($key): void {
            unset($this->busy[$key]);
        });
    }

    /** @return PromiseInterface<Message> */
    private function dm(MessageBuilder|string $message): PromiseInterface
    {
        $user = $this->discord->users->get('id', $this->ownerId);
        $owner = $user ? resolve($user) : $this->discord->users->fetch($this->ownerId);

        return $owner->then(static fn(User $owner) => $owner->sendMessage($message));
    }

    private function fail(string $what, \Throwable $e): void
    {
        $this->logger->warning("Could not {$what}: {$e->getMessage()}");
        $this->dm("⚠️ Could not {$what}: {$e->getMessage()}");
    }

    /** @param array<string, mixed> $edition */
    private function window(array $edition): Window
    {
        return Window::fromArray($edition['window'], $this->tz);
    }
}
