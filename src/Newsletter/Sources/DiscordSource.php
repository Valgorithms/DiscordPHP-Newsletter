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

namespace Newsletter\Sources;

use Newsletter\Window;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * The owner's Discord activity in the servers they manage, read back from the
 * {@see DiscordActivityLog} the bot keeps during the day, plus (optionally)
 * moderation actions pulled from each server's audit log at digest time.
 *
 * Other members' message *content* is never recorded; the community only
 * appears as counts.
 *
 * @since 1.0.0
 */
final class DiscordSource implements Source
{
    /** Cap on quoted snippets of the owner's own messages. */
    private const MAX_SNIPPETS = 40;

    /** @var (callable(Window): PromiseInterface<list<string>>)|null */
    private $auditLines;

    /** @var (callable(): void)|null */
    private $flush;

    /**
     * @param callable|null $auditLines `fn(Window): PromiseInterface<list<string>>`, the owner's
     *                                  moderation actions as lines, e.g. `[Guild] banned user#1 (spam)`.
     * @param callable|null $flush      Called before reading, so in-memory counters reach the log.
     */
    public function __construct(
        private readonly DiscordActivityLog $log,
        ?callable $auditLines = null,
        ?callable $flush = null,
    ) {
        $this->auditLines = $auditLines;
        $this->flush = $flush;
    }

    public function name(): string
    {
        return 'discord';
    }

    public function collect(Window $window): PromiseInterface
    {
        if ($this->flush) {
            ($this->flush)();
        }
        $entries = $this->log->between($window);
        $audit = $this->auditLines
            ? ($this->auditLines)($window)->then(null, static fn(\Throwable $e) => ['__error__' => 'audit log: ' . $e->getMessage()])
            : resolve([]);

        return $audit->then(function (array $lines) use ($entries): SourceReport {
            $errors = [];
            if (isset($lines['__error__'])) {
                $errors[] = $lines['__error__'];
                $lines = [];
            }

            return $this->report($entries, array_values($lines), $errors);
        });
    }

    /**
     * @param list<array<string, mixed>> $entries    Log entries inside the window.
     * @param list<string>               $moderation Audit-log lines.
     * @param list<string>               $errors
     */
    public function report(array $entries, array $moderation = [], array $errors = []): SourceReport
    {
        $highlights = [];
        $perChannel = [];   // "Guild #channel" => count
        $snippets = [];
        $voice = [];        // "Guild 🔊channel" => minutes
        $pulse = [];        // guild => [messages, joins, leaves, channels[]]
        $sent = 0;

        foreach ($entries as $e) {
            $guild = (string) ($e['guild'] ?? 'DM');
            switch ($e['type'] ?? '') {
                case 'message':
                    $sent++;
                    $where = "{$guild} #" . ($e['channel'] ?? '?');
                    $perChannel[$where] = ($perChannel[$where] ?? 0) + 1;
                    $text = trim((string) ($e['text'] ?? ''));
                    if ($text !== '' && count($snippets) < self::MAX_SNIPPETS) {
                        $snippets[] = "[{$where}] said: \"{$text}\"" . (! empty($e['attachments']) ? " (+{$e['attachments']} attachment(s))" : '');
                    }
                    break;

                case 'thread':
                    $highlights[] = "[{$guild} #" . ($e['channel'] ?? '?') . '] started thread "' . ($e['name'] ?? '') . '"';
                    break;

                case 'voice':
                    $where = "{$guild} 🔊" . ($e['channel'] ?? '?');
                    $voice[$where] = ($voice[$where] ?? 0) + (int) ($e['minutes'] ?? 0);
                    break;

                case 'pulse':
                    $p = $pulse[$guild] ?? ['messages' => 0, 'joins' => 0, 'leaves' => 0, 'channels' => []];
                    $p['messages'] += (int) ($e['messages'] ?? 0);
                    $p['joins'] += (int) ($e['joins'] ?? 0);
                    $p['leaves'] += (int) ($e['leaves'] ?? 0);
                    foreach ((array) ($e['channels'] ?? []) as $channel => $count) {
                        $p['channels'][$channel] = ($p['channels'][$channel] ?? 0) + (int) $count;
                    }
                    $pulse[$guild] = $p;
                    break;
            }
        }

        arsort($perChannel);
        foreach ($perChannel as $where => $count) {
            $highlights[] = "[{$where}] sent {$count} message" . ($count === 1 ? '' : 's');
        }
        array_push($highlights, ...$snippets);
        foreach ($voice as $where => $minutes) {
            if ($minutes > 0) {
                $highlights[] = "[{$where}] spent " . SteamSource::duration($minutes) . ' in voice';
            }
        }
        foreach ($moderation as $line) {
            $highlights[] = $line;
        }
        foreach ($pulse as $guild => $p) {
            arsort($p['channels']);
            $top = array_slice($p['channels'], 0, 3, true);
            $highlights[] = "[{$guild}] community pulse: {$p['messages']} messages from members"
                . ($top ? ' (busiest: ' . implode(', ', array_map(static fn($c, $n) => "#{$c} {$n}", array_keys($top), $top)) . ')' : '')
                . ($p['joins'] ? ", {$p['joins']} joined" : '')
                . ($p['leaves'] ? ", {$p['leaves']} left" : '');
        }

        $stats = array_filter([
            'messages_sent' => $sent,
            'channels' => count($perChannel),
            'voice_time' => $voice ? SteamSource::duration(array_sum($voice)) : 0,
            'moderation_actions' => count($moderation),
            'community_messages' => array_sum(array_column($pulse, 'messages')),
            'new_members' => array_sum(array_column($pulse, 'joins')),
        ]);

        return new SourceReport('discord', 'Discord activity (servers I manage)', $highlights, $stats, $errors);
    }
}
