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
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;
use Newsletter\Draft;
use Newsletter\Pipeline;
use Newsletter\RedditPublisher;
use Newsletter\ReplyInterpreter;
use Newsletter\SitePublisher;
use Newsletter\Sources\SourceReport;
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
 *  - DM commands: `!generate`, `!status`, `!publish`, `!help`.
 *
 * An approved edition is posted to the newsletter channel and, when a
 * {@see SitePublisher} is configured, committed to each website too.
 *
 * All state lives in the {@see StateStore}, and button ids carry the edition
 * key and revision, so drafts stay actionable across restarts and a stale
 * revision's buttons cannot post an outdated draft.
 *
 * @since 1.0.0
 */
final class ApprovalFlow
{
    /** Custom id prefix of the "Request edits" modal: `newsletter-edit:{key}`. */
    private const EDIT_MODAL = 'newsletter-edit:';

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
        private readonly ?SitePublisher $site = null,
        private readonly ?RedditPublisher $reddit = null,
        private readonly string $redditFooter = '',
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
        return $this->dm(Renderer::approval($edition, $this->window($edition), $this->destinations()))
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
                    $this->crosspost($edition['key'], $posted);
                    $this->publishToSites($edition['key']);
                    $this->publishToReddit($edition['key']);

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

    /**
     * Writes a pending edition again from its stored activity, as a new
     * revision: for a draft the model failed on, or one to start over.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function rewrite(string $key): PromiseInterface
    {
        return $this->exclusive($key, function (array $edition): PromiseInterface {
            $edition['status'] = StateStore::STATUS_REVISING;
            $this->state->putEdition($edition);
            $this->dm("✍️ Rewriting the {$edition['key']} draft from scratch… (the local model can take a few minutes)");
            $reports = array_map(static fn(array $r) => SourceReport::fromArray($r), (array) ($edition['reports'] ?? []));

            return $this->writer->write($this->window($edition), $reports)->then(function (array $written) use ($edition): PromiseInterface {
                $edition['draft'] = $written['draft']->toArray();
                $edition['notes'] = $written['notes'];
                $edition['fallback'] = $written['fallback'];
                $edition['revision']++;
                $this->state->putEdition($edition);

                return $this->sendForApproval($edition);
            }, function (\Throwable $e) use ($edition): PromiseInterface {
                $edition['status'] = StateStore::STATUS_PENDING;
                $this->state->putEdition($edition);

                return reject($e);
            });
        });
    }

    // --- gateway handlers ---------------------------------------------------------

    private function onInteraction(Interaction $interaction): void
    {
        if ($interaction->type === Interaction::TYPE_MODAL_SUBMIT
            && str_starts_with((string) $interaction->data?->custom_id, self::EDIT_MODAL)) {
            $this->onEditSubmitted($interaction, substr((string) $interaction->data->custom_id, strlen(self::EDIT_MODAL)));

            return;
        }
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
        $modal = ModalBuilder::new('Edit the newsletter', self::EDIT_MODAL . $key, [Label::new('What should change?', $input)]);

        // The submission is handled by onInteraction() rather than a callback here, so
        // it still arrives after a restart and however long the owner takes to type.
        $interaction->respondWithModal($modal)
            ->then(null, fn(\Throwable $e) => $this->logger->warning("Could not open the edit box: {$e->getMessage()}"));
    }

    private function onEditSubmitted(Interaction $submit, string $key): void
    {
        if ($submit->user?->id !== $this->ownerId) {
            $submit->respondWithMessage(MessageBuilder::new()->setContent('Only the newsletter owner can do that.'), true);

            return;
        }
        $submit->acknowledge();

        $instructions = trim((string) self::submittedValue($submit->data->components ?? [], 'instructions'));
        $edition = $this->state->edition($key);
        if ($instructions === '') {
            $this->logger->warning("The edit box for {$key} arrived without text");
            $this->dm('⚠️ Your edits arrived empty, so nothing changed. Reply to the draft with them in plain words instead.');
        } elseif ($edition === null || $edition['status'] !== StateStore::STATUS_PENDING) {
            $this->dm("That draft is " . ($edition['status'] ?? 'gone') . ', so the edits were not applied.');
        } else {
            $this->logger->info("Edits requested for {$key}");
            $this->revise($key, $instructions)->then(null, fn(\Throwable $e) => $this->logger->warning("Revision failed: {$e->getMessage()}"));
        }
    }

    /**
     * Finds a submitted modal field's value by custom id, however it is nested:
     * directly, inside an action row's `components`, or inside a Label's
     * `component`. Walked here rather than through DiscordPHP's modal-submit
     * helper, which misses fields inside Labels.
     *
     * @param iterable<mixed> $components
     */
    public static function submittedValue(iterable $components, string $customId): ?string
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }
            if (($component->custom_id ?? null) === $customId && ($component->value ?? null) !== null) {
                return (string) $component->value;
            }
            $children = [];
            if (($component->component ?? null) !== null) {
                $children[] = $component->component;
            }
            foreach ($component->components ?? [] as $child) {
                $children[] = $child;
            }
            if ($children && ($value = self::submittedValue($children, $customId)) !== null) {
                return $value;
            }
        }

        return null;
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
            [$command, $argument] = array_pad(preg_split('/\s+/', substr($content, 1), 2), 2, '');
            $this->command($message, strtolower($command), trim($argument));

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

    private function command(Message $message, string $command, string $argument = ''): void
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

            case 'rewrite':
                // Have the model write the pending draft again from the same activity (no re-collection).
                $pending = $this->state->pendingEditions();
                $target = $argument !== '' ? $this->state->edition($argument) : ($pending[0] ?? null);
                if ($target === null) {
                    $message->reply('No draft is waiting on you to rewrite.');
                } else {
                    $this->rewrite($target['key'])->then(null, fn(\Throwable $e) => $this->fail('rewrite', $e));
                }
                break;

            case 'publish':
                // Re-publish a posted edition to the websites, e.g. after fixing a token.
                $key = $argument;
                $posted = array_values(array_filter(
                    (array) $this->state->get('editions', []),
                    static fn($e) => is_array($e) && ($e['status'] ?? null) === StateStore::STATUS_POSTED && ($key === '' || $e['key'] === $key),
                ));
                usort($posted, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
                $announcement = $this->discord->getChannel($this->channelId)?->type === Channel::TYPE_GUILD_ANNOUNCEMENT;
                if ($posted === []) {
                    $message->reply($key === '' ? 'No posted edition to publish yet.' : "No posted edition \"{$key}\".");
                } elseif ($this->site === null && $this->reddit === null && ! $announcement) {
                    $message->reply('Nothing to publish to: the newsletter channel is not an announcement channel, and no websites (PUBLISH_TARGETS) or Reddit targets (REDDIT_TARGETS) are configured.');
                } else {
                    $message->reply("🔁 Publishing the {$posted[0]['key']} newsletter again…");
                    $this->crosspost($posted[0]['key']);
                    $this->publishToSites($posted[0]['key']);
                    $this->publishToReddit($posted[0]['key']);
                }
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
                    '`!rewrite [date]`: have the model write the waiting draft again from scratch',
                    '`!publish [date]`: retry publishing a posted edition to following servers, the websites and Reddit (the latest if no date)',
                ]));
        }
    }

    /**
     * Publishes the posted newsletter to the servers following the channel, when
     * it is an announcement channel, and DMs the outcome. Skipped for any other
     * kind of channel, and for an edition already published.
     *
     * @param Message|null $posted The posted message, when it is at hand; fetched otherwise.
     */
    private function crosspost(string $key, ?Message $posted = null): void
    {
        $edition = $this->state->edition($key);
        $channel = $this->discord->getChannel($this->channelId);
        if ($edition === null || ! empty($edition['crossposted']) || $channel?->type !== Channel::TYPE_GUILD_ANNOUNCEMENT || empty($edition['posted_message_id'])) {
            return;
        }

        ($posted ? resolve($posted) : $channel->messages->fetch($edition['posted_message_id']))
            ->then(static fn(Message $message): PromiseInterface => $message->crossposted ? resolve($message) : $message->crosspost())
            ->then(function () use ($key): void {
                $edition = $this->state->edition($key);
                $edition['crossposted'] = true;
                $this->state->putEdition($edition);
                $this->logger->info("Edition {$key} published to following servers");
                $this->dm("📣 Published the {$key} newsletter to the servers that follow the channel.");
            }, function (\Throwable $e) use ($key): void {
                $this->logger->warning("Could not publish edition {$key} to following servers: {$e->getMessage()}");
                $this->dm("⚠️ Posted, but could not publish the {$key} newsletter to following servers ({$e->getMessage()}). Send `!publish {$key}` to try again.");
            });
    }

    /**
     * Posts a posted edition to each Reddit target (editing the post where it
     * already has one) and DMs the outcome. A failure never undoes the Discord
     * post: `!publish` retries it, and only edits the posts that went through.
     */
    private function publishToReddit(string $key): void
    {
        $edition = $this->state->edition($key);
        if ($this->reddit === null || $edition === null) {
            return;
        }
        $this->reddit->publish($edition, $this->window($edition), $this->redditFooter)->then(function (array $results) use ($key): void {
            $edition = $this->state->edition($key);
            $lines = [];
            foreach ($results as $target => $post) {
                $where = RedditPublisher::display($target);
                if (isset($post['error'])) {
                    $lines[] = "• {$where}: failed ({$post['error']})";
                    continue;
                }
                $edition['reddit'][$target] = ['id' => $post['id'], 'url' => $post['url'], 'published_at' => date(DATE_ATOM)];
                $lines[] = "• {$where}: " . ($post['edited'] ? 'updated' : 'posted') . ($post['url'] ? " {$post['url']}" : '');
            }
            $this->state->putEdition($edition);
            $failed = count(array_filter($results, static fn($p) => isset($p['error'])));
            $this->logger->info("Reddit publishing for {$key}: " . (count($results) - $failed) . ' done, ' . $failed . ' failed');
            $this->dm("🟠 Reddit publishing for {$key}:\n" . implode("\n", $lines) . ($failed ? "\nSend `!publish {$key}` to retry." : ''));
        });
    }

    /** @return list<string> Where an approval publishes besides the newsletter channel. */
    private function destinations(): array
    {
        return array_merge($this->site?->describe() ?? [], $this->reddit?->describe() ?? []);
    }

    /**
     * Commits a posted edition to each website and DMs the outcome. A failed
     * site never undoes the Discord post: `!publish` retries it.
     */
    private function publishToSites(string $key): void
    {
        $edition = $this->state->edition($key);
        if ($this->site === null || $edition === null) {
            return;
        }
        $this->site->publish($edition, $this->window($edition))->then(function (array $lines) use ($key): void {
            $edition = $this->state->edition($key);
            $edition['site'] = ['published_at' => date(DATE_ATOM), 'results' => $lines];
            $this->state->putEdition($edition);
            $this->dm("🌐 Website publishing for {$key} (each site rebuilds in a few minutes):\n" . implode("\n", array_map(static fn($l) => "• {$l}", $lines)));
        });
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
