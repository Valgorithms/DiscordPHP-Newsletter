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

/**
 * One newsletter, as structured text: a headline, an intro, titled sections
 * and a sign-off.
 *
 * The LLM writes and revises this as JSON ({@see SCHEMA}); keeping it
 * structured (rather than one markdown blob) lets the renderer respect
 * Discord's size limits and lets a revision touch only what it must.
 *
 * @since 1.0.0
 */
final class Draft
{
    public const MAX_SECTIONS = 8;
    public const MAX_HEADLINE = 120;
    public const MAX_SECTION_BODY = 1500;

    /** JSON schema handed to Ollama's structured-output `format`. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string'],
            'intro' => ['type' => 'string'],
            'sections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'body'],
                ],
            ],
            'signoff' => ['type' => 'string'],
        ],
        'required' => ['headline', 'intro', 'sections', 'signoff'],
    ];

    /**
     * @param list<array{title: string, body: string}> $sections
     */
    public function __construct(
        public readonly string $headline,
        public readonly string $intro,
        public readonly array $sections,
        public readonly string $signoff = '',
    ) {}

    /**
     * Parses a model reply. Tolerates code fences and chatter around the JSON
     * object, which small local models add even when asked not to.
     *
     * @throws \InvalidArgumentException When no usable draft can be found.
     */
    public static function fromLlmJson(string $reply): self
    {
        $json = trim($reply);
        $json = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($json, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('The reply was not a JSON object.');
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException When required fields are missing or empty.
     */
    public static function fromArray(array $data): self
    {
        $headline = self::clean($data['headline'] ?? $data['title'] ?? '');
        if ($headline === '') {
            throw new \InvalidArgumentException('The draft has no headline.');
        }

        $sections = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $title = self::clean($section['title'] ?? $section['heading'] ?? '');
            $body = self::clean($section['body'] ?? $section['content'] ?? '', true);
            if ($body !== '') {
                $sections[] = ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, self::MAX_SECTION_BODY)];
            }
        }
        $intro = self::clean($data['intro'] ?? '', true);
        if ($sections === [] && $intro === '') {
            throw new \InvalidArgumentException('The draft has no content.');
        }

        return new self(
            mb_substr($headline, 0, self::MAX_HEADLINE),
            $intro,
            array_slice($sections, 0, self::MAX_SECTIONS),
            self::clean($data['signoff'] ?? '', true),
        );
    }

    /** @return array{headline: string, intro: string, sections: list<array{title: string, body: string}>, signoff: string} */
    public function toArray(): array
    {
        return ['headline' => $this->headline, 'intro' => $this->intro, 'sections' => $this->sections, 'signoff' => $this->signoff];
    }

    /** Discord-flavoured markdown, used for previews and as prompt context. */
    public function toMarkdown(): string
    {
        $parts = ["# {$this->headline}"];
        if ($this->intro !== '') {
            $parts[] = $this->intro;
        }
        foreach ($this->sections as $section) {
            $parts[] = ($section['title'] !== '' ? "## {$section['title']}\n" : '') . $section['body'];
        }
        if ($this->signoff !== '') {
            $parts[] = $this->signoff;
        }

        return implode("\n\n", $parts);
    }

    private static function clean(mixed $text, bool $multiline = false): string
    {
        if (! is_string($text)) {
            return '';
        }
        // Never let the model ping a whole server.
        $text = str_ireplace(['@everyone', '@here'], ["@\u{200B}everyone", "@\u{200B}here"], $text);
        $text = $multiline ? (string) preg_replace("/\n{3,}/", "\n\n", $text) : (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
