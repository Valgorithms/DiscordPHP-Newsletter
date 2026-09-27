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

/**
 * Append-only JSON-lines log (`var/discord-activity.jsonl`) of what the gateway
 * saw during the day: the owner's own messages, threads and voice sessions, and
 * periodic per-server "pulse" counters for the community as a whole.
 *
 * Discord has no "what did this user do today" endpoint, so the bot records the
 * day as it happens and {@see DiscordSource} reads it back at digest time.
 *
 * @since 1.0.0
 */
final class DiscordActivityLog
{
    public function __construct(private readonly string $path) {}

    /** @param array<string, mixed> $data */
    public function record(string $type, array $data, ?\DateTimeImmutable $at = null): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $line = json_encode(['type' => $type, 'at' => ($at ?? new \DateTimeImmutable())->format(DATE_ATOM)] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Entries recorded inside `$window`, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function between(Window $window): array
    {
        $entries = [];
        foreach ($this->lines() as $entry) {
            if (isset($entry['at']) && $window->contains(new \DateTimeImmutable($entry['at']))) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Rewrites the log without entries older than `$days`. */
    public function prune(int $days = 7): void
    {
        if (! is_file($this->path)) {
            return;
        }
        $cutoff = new \DateTimeImmutable("-{$days} days");
        $keep = array_filter($this->lines(), static fn(array $e) => isset($e['at']) && new \DateTimeImmutable($e['at']) >= $cutoff);
        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, implode('', array_map(static fn($e) => json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", $keep)));
        rename($tmp, $this->path);
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $entries = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
