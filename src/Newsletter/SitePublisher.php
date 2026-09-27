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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Publishes approved editions to static websites by committing them to a JSON
 * file in each site's repository (GitHub contents API). The push to the site's
 * branch then rebuilds and deploys it, as any other change would.
 *
 * Every target file has the same shape, `{"editions": [ … newest first … ]}`,
 * and an edition is replaced in place when it is published again.
 *
 * @link https://docs.github.com/en/rest/repos/contents#create-or-update-file-contents
 *
 * @since 1.0.0
 */
final class SitePublisher
{
    private const API = 'https://api.github.com';

    private readonly LoggerInterface $logger;

    /** @var callable(string): ?string */
    private $tokenFor;

    /**
     * @param list<array{repo: string, branch: string, path: string}> $targets
     * @param callable(string $owner): ?string                        $tokenFor A token with contents write access to that owner's repository.
     */
    public function __construct(
        private readonly JsonClient $http,
        private readonly array $targets,
        callable $tokenFor,
        ?LoggerInterface $logger = null,
    ) {
        $this->tokenFor = $tokenFor;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Parses `owner/repo[@branch]:path` entries, comma-separated, e.g.
     * `discord-php/DiscordPHP.org:data/newsletter.json,valzargaming/valgorithms.com@main:site/data/newsletter.json`.
     *
     * @return list<array{repo: string, branch: string, path: string}>
     *
     * @throws \InvalidArgumentException On an entry that does not have that shape.
     */
    public static function parseTargets(string $spec): array
    {
        $targets = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $entry) {
            if (! preg_match('#^([\w.-]+/[\w.-]+)(?:@([\w./-]+))?:([\w./-]+\.json)$#', $entry, $m)) {
                throw new \InvalidArgumentException("PUBLISH_TARGETS entry \"{$entry}\" is not owner/repo[@branch]:path/to/file.json");
            }
            $targets[] = ['repo' => $m[1], 'branch' => $m[2] !== '' ? $m[2] : 'main', 'path' => $m[3]];
        }

        return $targets;
    }

    /** @return list<string> `owner/repo` of each target, for messages. */
    public function describe(): array
    {
        return array_map(static fn(array $t) => $t['repo'], $this->targets);
    }

    /**
     * The entry a site stores for an edition.
     *
     * @param array<string, mixed> $edition
     *
     * @return array<string, mixed>
     */
    public static function entry(array $edition, Window $window): array
    {
        $draft = Draft::fromArray((array) $edition['draft']);

        return [
            'key' => (string) $edition['key'],
            'date' => $window->key(),
            'headline' => $draft->headline,
            'intro' => $draft->intro,
            'sections' => $draft->sections,
            'signoff' => $draft->signoff,
            'published_at' => date(DATE_ATOM),
        ];
    }

    /**
     * `$doc` with `$entry` added, or replacing the edition with the same key,
     * newest first.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $entry
     *
     * @return array{editions: list<array<string, mixed>>}
     */
    public static function upsert(array $doc, array $entry): array
    {
        $editions = array_values(array_filter(
            is_array($doc['editions'] ?? null) ? $doc['editions'] : [],
            static fn($e) => is_array($e) && ($e['key'] ?? null) !== $entry['key'],
        ));
        $editions[] = $entry;
        usort($editions, static fn($a, $b) => [(string) ($b['date'] ?? ''), (string) ($b['key'] ?? '')] <=> [(string) ($a['date'] ?? ''), (string) ($a['key'] ?? '')]);

        return ['editions' => $editions] + $doc;
    }

    /**
     * Commits the edition to every target, one after another. Never rejects:
     * resolves with one line per target, a commit link or the reason it failed.
     *
     * @param array<string, mixed> $edition
     *
     * @return PromiseInterface<list<string>>
     */
    public function publish(array $edition, Window $window): PromiseInterface
    {
        $entry = self::entry($edition, $window);
        $results = [];
        $chain = resolve(null);
        foreach ($this->targets as $target) {
            $chain = $chain->then(function () use ($target, $entry, &$results): PromiseInterface {
                return $this->commit($target, $entry, 1)->then(
                    function (string $url) use ($target, &$results): void {
                        $results[] = "{$target['repo']}: {$url}";
                    },
                    function (\Throwable $e) use ($target, &$results): void {
                        $this->logger->warning("Publishing to {$target['repo']} failed: {$e->getMessage()}");
                        $results[] = "{$target['repo']}: failed ({$e->getMessage()})";
                    },
                );
            });
        }

        return $chain->then(function () use (&$results): array {
            return $results;
        });
    }

    /**
     * @param array{repo: string, branch: string, path: string} $target
     * @param array<string, mixed>                              $entry
     *
     * @return PromiseInterface<string> The commit's URL.
     */
    private function commit(array $target, array $entry, int $retries): PromiseInterface
    {
        $owner = explode('/', $target['repo'])[0];
        $token = ($this->tokenFor)($owner);
        if ($token === null) {
            return reject(new \RuntimeException("no token for {$owner}; set PUBLISH_GITHUB_TOKEN"));
        }
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => "Bearer {$token}",
        ];
        $url = self::API . "/repos/{$target['repo']}/contents/" . implode('/', array_map('rawurlencode', explode('/', $target['path'])));

        return $this->http->get($url . '?ref=' . rawurlencode($target['branch']), $headers)
            ->then(
                static fn(array $file): array => [
                    json_decode((string) base64_decode(str_replace("\n", '', (string) ($file['content'] ?? '')), true), true) ?: [],
                    (string) ($file['sha'] ?? ''),
                ],
                static function (\Throwable $e): array {
                    if ($e->getCode() === 404) {
                        return [[], null]; // first edition: the file is created
                    }

                    throw $e;
                },
            )
            ->then(function (array $current) use ($url, $headers, $target, $entry): PromiseInterface {
                [$doc, $sha] = $current;
                $json = json_encode(self::upsert(is_array($doc) ? $doc : [], $entry), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                $payload = [
                    'message' => "Newsletter: {$entry['date']} — {$entry['headline']}",
                    'content' => base64_encode($json),
                    'branch' => $target['branch'],
                ];
                if ($sha) {
                    $payload['sha'] = $sha;
                }

                return $this->http->request('PUT', $url, $headers, $payload);
            })
            ->then(
                static fn(array $body): string => (string) ($body['commit']['html_url'] ?? 'committed'),
                function (\Throwable $e) use ($target, $entry, $retries): PromiseInterface {
                    // 409: the file changed between our read and write. Read it again once.
                    if ($retries > 0 && in_array($e->getCode(), [409, 422], true)) {
                        return $this->commit($target, $entry, $retries - 1);
                    }

                    return reject($e);
                },
            );
    }
}
