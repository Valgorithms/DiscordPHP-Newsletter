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

/**
 * What one source found inside a {@see \Newsletter\Window}: plain-language
 * highlight lines (the facts the LLM may use), a few headline numbers, and any
 * errors that made the picture incomplete.
 *
 * Everything is plain data so an edition can be persisted with the facts it was
 * written from, and revised later against the same facts.
 *
 * @since 1.0.0
 */
final class SourceReport
{
    /**
     * @param string                    $source     Machine name (`github`, `discord`, `steam`).
     * @param string                    $title      Section-style title for prompts and the fallback draft.
     * @param list<string>              $highlights One fact per line.
     * @param array<string, int|string> $stats      Headline numbers, e.g. `['commits' => 12]`.
     * @param list<string>              $errors     Why the report may be incomplete.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $title,
        public readonly array $highlights = [],
        public readonly array $stats = [],
        public readonly array $errors = [],
    ) {}

    /** A report that only carries an error (the source failed outright). */
    public static function failed(string $source, string $title, string $error): self
    {
        return new self($source, $title, [], [], [$error]);
    }

    /** True when there is nothing worth writing about. */
    public function isEmpty(): bool
    {
        return $this->highlights === [] && array_filter($this->stats, static fn($v) => $v !== 0 && $v !== '0' && $v !== '') === [];
    }

    /**
     * The facts as prompt text, capped at `$maxHighlights` lines so a busy day
     * cannot blow a small local model's context window.
     */
    public function toPromptText(int $maxHighlights = 80): string
    {
        $lines = ["### {$this->title}"];
        if ($this->stats) {
            $lines[] = 'Stats: ' . implode(', ', array_map(static fn($k, $v) => str_replace('_', ' ', (string) $k) . ": {$v}", array_keys($this->stats), $this->stats));
        }
        $shown = array_slice($this->highlights, 0, $maxHighlights);
        foreach ($shown as $highlight) {
            $lines[] = "- {$highlight}";
        }
        if (($more = count($this->highlights) - count($shown)) > 0) {
            $lines[] = "- … and {$more} more similar items";
        }
        if ($this->highlights === [] && ! $this->stats) {
            $lines[] = '- (no activity)';
        }
        foreach ($this->errors as $error) {
            $lines[] = "- NOTE: data may be incomplete ({$error})";
        }

        return implode("\n", $lines);
    }

    /** @return array{source: string, title: string, highlights: list<string>, stats: array<string, int|string>, errors: list<string>} */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'title' => $this->title,
            'highlights' => $this->highlights,
            'stats' => $this->stats,
            'errors' => $this->errors,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['source'] ?? ''),
            (string) ($data['title'] ?? ''),
            array_values(array_map('strval', (array) ($data['highlights'] ?? []))),
            (array) ($data['stats'] ?? []),
            array_values(array_map('strval', (array) ($data['errors'] ?? []))),
        );
    }
}
