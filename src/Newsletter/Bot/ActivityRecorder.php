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

use Discord\Discord;
use Discord\Http\Endpoint;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\AuditLog\Entry;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\WebSockets\Event;
use Newsletter\Sources\DiscordActivityLog;
use Newsletter\Window;
use React\Promise\PromiseInterface;

use function Discord\getSnowflakeTimestamp;
use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Listens to the gateway all day and writes what matters for the newsletter to
 * the {@see DiscordActivityLog}:
 *
 *  - the owner's own messages (text clipped, with a jump link), threads and
 *    voice sessions in the tracked servers;
 *  - a per-server "pulse" of member message counts, joins and leaves, flushed
 *    every few minutes (other members' message content is never stored).
 *
 * It also reads the owner's moderation actions from each server's audit log at
 * digest time ({@see auditLines()}).
 *
 * @since 1.0.0
 */
final class ActivityRecorder
{
    /** @var array<string, array{guild: string, messages: int, joins: int, leaves: int, channels: array<string, int>}> */
    private array $pulse = [];

    /** @var array<string, array{guild: string, channel: string, channel_id: string, since: int}> Open voice sessions by guild id. */
    private array $voice = [];

    /**
     * @param list<string> $guildIds          Servers to track; empty = every server the bot is in.
     * @param list<string> $ignoredChannelIds Channels never recorded (e.g. staff-only or private ones).
     */
    public function __construct(
        private readonly Discord $discord,
        private readonly DiscordActivityLog $log,
        private readonly string $ownerId,
        private readonly array $guildIds = [],
        private readonly array $ignoredChannelIds = [],
        private readonly float $flushInterval = 300.0,
    ) {}

    public function register(): void
    {
        $this->discord->on(Event::MESSAGE_CREATE, fn(Message $message) => $this->onMessage($message));
        $this->discord->on(Event::THREAD_CREATE, fn(Thread $thread) => $this->onThread($thread));
        $this->discord->on(Event::VOICE_STATE_UPDATE, fn(VoiceStateUpdate $state) => $this->onVoice($state));
        $this->discord->on(Event::GUILD_MEMBER_ADD, fn(Member $member) => $this->bump($member->guild_id, 'joins'));
        $this->discord->on(Event::GUILD_MEMBER_REMOVE, fn(Member $member) => $this->bump($member->guild_id, 'leaves'));
        $this->discord->getLoop()->addPeriodicTimer($this->flushInterval, fn() => $this->flush());
        $this->log->prune();
    }

    /**
     * Writes the buffered pulse counters and the elapsed part of any open voice
     * session to the log. Called on a timer and right before a digest reads the log.
     */
    public function flush(): void
    {
        foreach ($this->pulse as $guildId => $pulse) {
            $this->log->record('pulse', ['guild_id' => $guildId] + $pulse);
        }
        $this->pulse = [];

        $now = time();
        foreach ($this->voice as $guildId => $session) {
            $this->writeVoice($session, $now);
            $this->voice[$guildId]['since'] = $now;
        }
    }

    /**
     * The owner's audit-log actions inside `$window`, as lines like
     * `[Server] MEMBER BAN ADD → someone (reason: spam)`.
     *
     * @return PromiseInterface<list<string>>
     */
    public function auditLines(Window $window): PromiseInterface
    {
        $names = array_flip((new \ReflectionClass(Entry::class))->getConstants());
        $lookups = [];
        foreach ($this->discord->guilds as $guild) {
            if (! $this->tracked($guild->id)) {
                continue;
            }
            $endpoint = Endpoint::bind(Endpoint::AUDIT_LOG, $guild->id);
            $endpoint->addQuery('user_id', $this->ownerId);
            $endpoint->addQuery('limit', 100);
            $guildName = $guild->name;

            $lookups[] = $this->discord->getHttpClient()->get($endpoint)->then(
                static function ($response) use ($window, $names, $guildName): array {
                    $users = [];
                    foreach ((array) ($response->users ?? []) as $user) {
                        $users[$user->id] = $user->global_name ?? $user->username;
                    }
                    $lines = [];
                    foreach ((array) ($response->audit_log_entries ?? []) as $entry) {
                        $at = (new \DateTimeImmutable())->setTimestamp((int) getSnowflakeTimestamp((string) $entry->id));
                        if (! $window->contains($at)) {
                            continue;
                        }
                        $action = strtolower(str_replace('_', ' ', $names[$entry->action_type] ?? "action {$entry->action_type}"));
                        $target = isset($entry->target_id) ? ($users[$entry->target_id] ?? null) : null;
                        $lines[] = "[{$guildName}] moderation: {$action}" . ($target ? " → {$target}" : '') . (! empty($entry->reason) ? " (reason: {$entry->reason})" : '');
                    }

                    return array_reverse($lines);
                },
                static fn(): array => [], // missing View Audit Log permission etc.
            );
        }

        return $lookups ? all($lookups)->then(static fn(array $lists) => array_merge([], ...array_values($lists))) : resolve([]);
    }

    private function onMessage(Message $message): void
    {
        if (! $this->tracked($message->guild_id) || in_array($message->channel_id, $this->ignoredChannelIds, true)) {
            return;
        }
        $author = $message->author;
        if ($author === null || $author->bot || $message->webhook_id) {
            return;
        }
        $guild = $message->guild?->name ?? (string) $message->guild_id;
        $channel = $message->channel?->name ?? (string) $message->channel_id;

        $this->bump($message->guild_id, 'messages', $channel);

        if ($author->id === $this->ownerId) {
            $text = trim((string) preg_replace('/\s+/', ' ', (string) $message->content));
            $this->log->record('message', [
                'guild_id' => $message->guild_id,
                'guild' => $guild,
                'channel' => $channel,
                'text' => mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text,
                'attachments' => count($message->attachments ?? []),
                'url' => $message->link,
            ]);
        }
    }

    private function onThread(Thread $thread): void
    {
        if ($thread->owner_id !== $this->ownerId || ! $this->tracked($thread->guild_id) || in_array($thread->parent_id, $this->ignoredChannelIds, true)) {
            return;
        }
        $this->log->record('thread', [
            'guild_id' => $thread->guild_id,
            'guild' => $thread->guild?->name ?? (string) $thread->guild_id,
            'channel' => $thread->parent?->name ?? (string) $thread->parent_id,
            'name' => $thread->name,
        ]);
    }

    private function onVoice(VoiceStateUpdate $state): void
    {
        if ($state->user_id !== $this->ownerId || ! $this->tracked($state->guild_id)) {
            return;
        }
        $guildId = (string) $state->guild_id;
        $open = $this->voice[$guildId] ?? null;
        if ($open && $open['channel_id'] === $state->channel_id) {
            return; // mute/deafen/stream toggles
        }
        if ($open) {
            $this->writeVoice($open, time());
            unset($this->voice[$guildId]);
        }
        if ($state->channel_id && ! in_array($state->channel_id, $this->ignoredChannelIds, true)) {
            $this->voice[$guildId] = [
                'guild' => $state->guild?->name ?? $guildId,
                'channel' => $state->channel?->name ?? (string) $state->channel_id,
                'channel_id' => (string) $state->channel_id,
                'since' => time(),
            ];
        }
    }

    /** @param array{guild: string, channel: string, channel_id: string, since: int} $session */
    private function writeVoice(array $session, int $until): void
    {
        $minutes = intdiv($until - $session['since'], 60);
        if ($minutes > 0) {
            $this->log->record('voice', ['guild' => $session['guild'], 'channel' => $session['channel'], 'minutes' => $minutes]);
        }
    }

    private function bump(?string $guildId, string $field, ?string $channel = null): void
    {
        if (! $this->tracked($guildId)) {
            return;
        }
        $this->pulse[$guildId] ??= [
            'guild' => $this->discord->guilds->get('id', $guildId)?->name ?? $guildId,
            'messages' => 0, 'joins' => 0, 'leaves' => 0, 'channels' => [],
        ];
        $this->pulse[$guildId][$field]++;
        if ($channel !== null) {
            $this->pulse[$guildId]['channels'][$channel] = ($this->pulse[$guildId]['channels'][$channel] ?? 0) + 1;
        }
    }

    private function tracked(?string $guildId): bool
    {
        return $guildId !== null && ($this->guildIds === [] || in_array($guildId, $this->guildIds, true));
    }
}
