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

use Newsletter\Http\JsonClient;
use Newsletter\Window;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * The owner's GitHub activity: the user events feed (pushes, pull requests,
 * issues, reviews, comments, releases, …) plus a commit search, which still
 * carries commit messages when a push event's payload does not.
 *
 * Private-repository activity is dropped unless `$includePrivate` is set, so a
 * token with private scope cannot leak private repo names into a public post.
 *
 * @link https://docs.github.com/en/rest/activity/events#list-events-for-the-authenticated-user
 * @link https://docs.github.com/en/rest/search/search#search-commits
 *
 * @since 1.0.0
 */
final class GitHubSource implements Source
{
    private const API = 'https://api.github.com';

    /** The events API serves at most 300 events (3 pages of 100). */
    private const MAX_EVENT_PAGES = 3;

    public function __construct(
        private readonly JsonClient $http,
        private readonly string $username,
        private readonly ?string $token = null,
        private readonly bool $includePrivate = false,
    ) {}

    public function name(): string
    {
        return 'github';
    }

    public function collect(Window $window): PromiseInterface
    {
        $errors = [];
        $events = $this->fetchEvents($window, 1, [])
            ->then(null, function (\Throwable $e) use (&$errors): array {
                $errors[] = 'events: ' . $e->getMessage();

                return [];
            });
        $commits = $this->searchCommits($window)
            ->then(null, function (\Throwable $e) use (&$errors): array {
                $errors[] = 'commit search: ' . $e->getMessage();

                return [];
            });

        return all([$events, $commits])->then(function (array $results) use ($window, &$errors): SourceReport {
            [$events, $commits] = $results;

            return $this->report($window, $events, $commits, $errors);
        });
    }

    /**
     * Builds the report from already-fetched API payloads. Public so it can be
     * tested without a transport.
     *
     * @param list<array<string, mixed>> $events  Raw `/users/{u}/events` items.
     * @param list<array<string, mixed>> $commits Raw `/search/commits` items.
     * @param list<string>               $errors
     */
    public function report(Window $window, array $events, array $commits, array $errors = []): SourceReport
    {
        $highlights = [];
        $stats = ['commits' => 0, 'pull_requests_opened' => 0, 'pull_requests_merged' => 0, 'issues_opened' => 0, 'issues_closed' => 0, 'reviews' => 0, 'comments' => 0];
        $repos = [];
        $seenShas = [];

        foreach (array_reverse($events) as $event) { // oldest first reads like a story
            if (! $this->keep($window, $event['created_at'] ?? null, (bool) ($event['public'] ?? true))) {
                continue;
            }
            $repo = (string) ($event['repo']['name'] ?? 'unknown repo');
            $payload = (array) ($event['payload'] ?? []);
            $line = null;

            switch ($event['type'] ?? '') {
                case 'PushEvent':
                    $branch = preg_replace('#^refs/heads/#', '', (string) ($payload['ref'] ?? ''));
                    $pushed = [];
                    foreach ((array) ($payload['commits'] ?? []) as $commit) {
                        if (isset($commit['sha'])) {
                            $seenShas[$commit['sha']] = true;
                        }
                        if (($commit['distinct'] ?? true) && isset($commit['message'])) {
                            $pushed[] = self::firstLine((string) $commit['message']);
                        }
                    }
                    $count = (int) ($payload['distinct_size'] ?? $payload['size'] ?? count($pushed));
                    $stats['commits'] += $count;
                    $line = $count > 0
                        ? "[{$repo}] pushed {$count} commit" . ($count === 1 ? '' : 's') . " to {$branch}" . ($pushed ? ': ' . implode('; ', array_slice($pushed, 0, 8)) : '')
                        : "[{$repo}] pushed to {$branch}";
                    break;

                case 'PullRequestEvent':
                    $pr = (array) ($payload['pull_request'] ?? []);
                    $action = (string) ($payload['action'] ?? '');
                    if ($action === 'closed' && ($pr['merged'] ?? false)) {
                        $action = 'merged';
                        $stats['pull_requests_merged']++;
                    } elseif ($action === 'opened') {
                        $stats['pull_requests_opened']++;
                    }
                    $line = "[{$repo}] {$action} pull request " . self::ref($payload['number'] ?? $pr['number'] ?? null, $pr['title'] ?? null);
                    break;

                case 'IssuesEvent':
                    $issue = (array) ($payload['issue'] ?? []);
                    $action = (string) ($payload['action'] ?? '');
                    if ($action === 'opened') {
                        $stats['issues_opened']++;
                    } elseif ($action === 'closed') {
                        $stats['issues_closed']++;
                    }
                    $line = "[{$repo}] {$action} issue " . self::ref($issue['number'] ?? null, $issue['title'] ?? null);
                    break;

                case 'IssueCommentEvent':
                    $issue = (array) ($payload['issue'] ?? []);
                    $stats['comments']++;
                    $kind = isset($issue['pull_request']) ? 'pull request' : 'issue';
                    $line = "[{$repo}] commented on {$kind} " . self::ref($issue['number'] ?? null, $issue['title'] ?? null)
                        . (isset($payload['comment']['body']) ? ': "' . self::clip((string) $payload['comment']['body'], 160) . '"' : '');
                    break;

                case 'PullRequestReviewEvent':
                    $pr = (array) ($payload['pull_request'] ?? []);
                    $stats['reviews']++;
                    $state = strtolower(str_replace('_', ' ', (string) ($payload['review']['state'] ?? 'reviewed')));
                    $line = "[{$repo}] reviewed pull request " . self::ref($pr['number'] ?? null, $pr['title'] ?? null) . " ({$state})";
                    break;

                case 'PullRequestReviewCommentEvent':
                    $stats['comments']++;
                    break; // counted; one line per inline comment would drown the rest

                case 'CreateEvent':
                    $type = (string) ($payload['ref_type'] ?? 'ref');
                    $line = $type === 'repository'
                        ? "[{$repo}] created the repository"
                        : "[{$repo}] created {$type} " . ($payload['ref'] ?? '');
                    break;

                case 'DeleteEvent':
                    $line = "[{$repo}] deleted " . ($payload['ref_type'] ?? 'ref') . ' ' . ($payload['ref'] ?? '');
                    break;

                case 'ReleaseEvent':
                    $release = (array) ($payload['release'] ?? []);
                    $line = "[{$repo}] " . ($payload['action'] ?? 'published') . ' release ' . ($release['name'] ?: ($release['tag_name'] ?? ''));
                    break;

                case 'ForkEvent':
                    $line = "forked {$repo}";
                    break;

                case 'WatchEvent':
                    $line = "starred {$repo}";
                    break;

                case 'PublicEvent':
                    $line = "[{$repo}] made the repository public";
                    break;

                case 'MemberEvent':
                    $line = "[{$repo}] " . ($payload['action'] ?? 'added') . ' collaborator ' . ($payload['member']['login'] ?? '');
                    break;

                case 'GollumEvent':
                    $line = "[{$repo}] edited the wiki";
                    break;
            }

            if ($line !== null) {
                $highlights[] = trim($line);
                $repos[$repo] = true;
            }
        }

        // Commits the search found that no push event already described.
        $extra = [];
        foreach ($commits as $item) {
            $sha = (string) ($item['sha'] ?? '');
            if ($sha === '' || isset($seenShas[$sha])) {
                continue;
            }
            if (! $this->keep($window, $item['commit']['author']['date'] ?? null, ! ($item['repository']['private'] ?? false))) {
                continue;
            }
            $repo = (string) ($item['repository']['full_name'] ?? 'unknown repo');
            $extra[$repo][] = self::firstLine((string) ($item['commit']['message'] ?? '')) . ' (' . substr($sha, 0, 7) . ')';
            $repos[$repo] = true;
        }
        foreach ($extra as $repo => $messages) {
            $highlights[] = "[{$repo}] commits: " . implode('; ', array_slice($messages, 0, 10)) . (count($messages) > 10 ? '; …' : '');
        }
        // A push whose payload carries no commit list still counted its size, and the
        // search may have found those same commits: take whichever count is larger.
        $stats['commits'] = max($stats['commits'], count($seenShas) + array_sum(array_map('count', $extra)));

        $stats = ['repositories' => count($repos)] + array_filter($stats);

        return new SourceReport('github', "GitHub activity (@{$this->username})", $highlights, $stats, $errors);
    }

    /**
     * @param list<array<string, mixed>> $acc
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    private function fetchEvents(Window $window, int $page, array $acc): PromiseInterface
    {
        // The authenticated variant of this route includes the owner's private events.
        $url = self::API . '/users/' . rawurlencode($this->username) . "/events?per_page=100&page={$page}";

        return $this->http->get($url, $this->headers())->then(function (array $events) use ($window, $page, $acc): PromiseInterface {
            $acc = array_merge($acc, array_values($events));
            $last = end($events);
            $older = $last && isset($last['created_at']) && new \DateTimeImmutable($last['created_at']) < $window->start;

            if ($older || count($events) < 100 || $page >= self::MAX_EVENT_PAGES) {
                return resolve($acc);
            }

            return $this->fetchEvents($window, $page + 1, $acc);
        });
    }

    /** @return PromiseInterface<list<array<string, mixed>>> */
    private function searchCommits(Window $window): PromiseInterface
    {
        $utc = new \DateTimeZone('UTC');
        $range = $window->start->setTimezone($utc)->format('Y-m-d\TH:i:sP') . '..' . $window->end->setTimezone($utc)->format('Y-m-d\TH:i:sP');
        $query = 'author:' . $this->username . ' author-date:' . $range;
        $url = self::API . '/search/commits?per_page=100&sort=author-date&order=asc&q=' . rawurlencode($query);

        return $this->http->get($url, $this->headers())
            ->then(static fn(array $body): array => array_values((array) ($body['items'] ?? [])));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token) {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $headers;
    }

    private function keep(Window $window, mixed $timestamp, bool $public): bool
    {
        if (! is_string($timestamp) || (! $public && ! $this->includePrivate)) {
            return false;
        }

        return $window->contains(new \DateTimeImmutable($timestamp));
    }

    private static function ref(mixed $number, mixed $title): string
    {
        $ref = $number !== null ? "#{$number}" : '';

        return trim($ref . (is_string($title) && $title !== '' ? " \"{$title}\"" : ''));
    }

    private static function firstLine(string $text): string
    {
        return self::clip(strtok($text, "\n") ?: '', 140);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
