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

use Newsletter\Http\JsonClient;
use React\Promise\PromiseInterface;

/**
 * Creates unpublished Open Collective updates. It has no publish operation.
 * Verified against opencollective-api 6f588a91e70e1c7cea7ca326f2852af6d2384acf.
 */
final class OpenCollectivePublisher
{
    private const API = 'https://api.opencollective.com/graphql/v2';
    private const CREATE = 'mutation NewsletterDraft($update: UpdateCreateInput!) { createUpdate(update: $update) { id slug publishedAt account { slug } } }';

    public function __construct(
        private readonly JsonClient $http,
        public readonly string $collective,
        private readonly string $token,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $collective) || trim($token) === '') {
            throw new \InvalidArgumentException('An Open Collective slug and personal token are required.');
        }
    }

    /** @return PromiseInterface<array{id: string, url: string}> */
    public function createDraft(Draft $draft): PromiseInterface
    {
        return $this->http->request('POST', self::API, ['Personal-Token' => $this->token], [
            'query' => self::CREATE,
            'variables' => ['update' => [
                'account' => ['slug' => $this->collective],
                'title' => $draft->headline,
                'html' => self::html($draft),
                'isPrivate' => false,
            ]],
        ])->then(
            function (array $response): array {
                $update = $response['data']['createUpdate'] ?? null;
                if (!empty($response['errors']) || !is_array($update)
                    || !is_string($update['id'] ?? null) || $update['id'] === ''
                    || !is_string($update['slug'] ?? null) || $update['slug'] === ''
                    || !array_key_exists('publishedAt', $update) || $update['publishedAt'] !== null
                    || ($update['account']['slug'] ?? null) !== $this->collective) {
                    throw new \RuntimeException('Open Collective did not confirm an unpublished draft. Check the collective before retrying.');
                }
                return [
                    'id' => $update['id'],
                    'url' => 'https://opencollective.com/' . rawurlencode($this->collective) . '/updates/' . rawurlencode($update['slug']),
                ];
            },
            static function (\Throwable $error): never {
                // Do not echo API bodies or credential-bearing transport errors into DMs.
                throw new \RuntimeException('Open Collective draft creation could not be confirmed. Check the collective before retrying.');
            },
        );
    }

    /** Escaped paragraph HTML, never raw HTML supplied by a model or source. */
    public static function html(Draft $draft): string
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = static function (string $text) use ($escape): string {
            $parts = preg_split('/\R{2,}/u', trim($text)) ?: [];
            return implode("\n", array_map(static fn(string $part): string => '<p>' . nl2br($escape($part), false) . '</p>', array_filter($parts, static fn(string $part): bool => $part !== '')));
        };
        $html = $paragraphs($draft->intro);
        foreach ($draft->sections as $section) {
            $html .= "\n<h2>" . $escape($section['title']) . "</h2>\n" . $paragraphs($section['body']);
        }
        if ($draft->signoff !== '') {
            $html .= "\n<p>— " . $escape($draft->signoff) . '</p>';
        }
        return $html;
    }
}
