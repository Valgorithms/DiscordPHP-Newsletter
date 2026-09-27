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

namespace Tests;

use Newsletter\Sources\GitHubSource;
use Newsletter\Window;

final class GitHubSourceTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-09-27T00:00:00Z'), new \DateTimeImmutable('2026-09-28T00:00:00Z'));
    }

    /** @return list<array<string, mixed>> newest first, like the API */
    private function events(): array
    {
        return [
            ['type' => 'PullRequestEvent', 'public' => true, 'created_at' => '2026-09-27T18:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP'],
                'payload' => ['action' => 'closed', 'number' => 1400, 'pull_request' => ['number' => 1400, 'title' => 'Add polls', 'merged' => true]]],
            ['type' => 'PushEvent', 'public' => false, 'created_at' => '2026-09-27T17:00:00Z', 'repo' => ['name' => 'me/secret'],
                'payload' => ['ref' => 'refs/heads/main', 'size' => 1, 'commits' => [['sha' => 'fff', 'message' => 'secret stuff']]]],
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T12:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP'],
                'payload' => ['ref' => 'refs/heads/master', 'size' => 2, 'distinct_size' => 2, 'commits' => [
                    ['sha' => 'aaa', 'message' => "Fix cache\n\nlong body", 'distinct' => true],
                    ['sha' => 'bbb', 'message' => 'Add tests', 'distinct' => true],
                ]]],
            ['type' => 'IssueCommentEvent', 'public' => true, 'created_at' => '2026-09-27T10:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP'],
                'payload' => ['issue' => ['number' => 12, 'title' => 'Crash on boot'], 'comment' => ['body' => 'Fixed in master']]],
            ['type' => 'WatchEvent', 'public' => true, 'created_at' => '2026-09-26T10:00:00Z', 'repo' => ['name' => 'yesterday/repo'], 'payload' => []],
        ];
    }

    public function testSummarisesPublicEventsInsideTheWindowOldestFirst(): void
    {
        $source = new GitHubSource(self::fakeHttp([]), 'valzargaming');
        $commits = [
            ['sha' => 'aaa', 'commit' => ['message' => 'Fix cache', 'author' => ['date' => '2026-09-27T11:59:00Z']], 'repository' => ['full_name' => 'discord-php/DiscordPHP', 'private' => false]],
            ['sha' => 'ccc', 'commit' => ['message' => 'Bump deps', 'author' => ['date' => '2026-09-27T09:00:00Z']], 'repository' => ['full_name' => 'Valgorithms/DiscordPHP-NHA', 'private' => false]],
        ];
        $report = $source->report($this->window(), $this->events(), $commits);

        $this->assertSame([
            '[discord-php/DiscordPHP] commented on issue #12 "Crash on boot": "Fixed in master"',
            '[discord-php/DiscordPHP] pushed 2 commits to master: Fix cache; Add tests',
            '[discord-php/DiscordPHP] merged pull request #1400 "Add polls"',
            '[Valgorithms/DiscordPHP-NHA] commits: Bump deps (ccc)',
        ], $report->highlights);
        $this->assertSame(3, $report->stats['commits']);
        $this->assertSame(1, $report->stats['pull_requests_merged']);
        $this->assertSame(2, $report->stats['repositories']);
        $this->assertStringNotContainsString('secret', $report->toPromptText());
    }

    public function testIncludesPrivateActivityOnlyWhenAskedTo(): void
    {
        $source = new GitHubSource(self::fakeHttp([]), 'valzargaming', 'token', includePrivate: true);

        $this->assertStringContainsString('me/secret', $source->report($this->window(), $this->events(), [])->toPromptText());
    }

    public function testCollectStopsPagingOnceEventsAreOlderThanTheWindow(): void
    {
        $http = self::fakeHttp(['/events' => $this->events(), '/search/commits' => ['items' => []]], $log);
        $report = self::settle((new GitHubSource($http, 'valzargaming', 'tkn'))->collect($this->window()));

        $this->assertCount(1, array_filter($log, static fn($u) => str_contains($u, '/events')));
        $this->assertStringContainsString('author%3Avalzargaming', implode(' ', $log));
        $this->assertSame([], $report->errors);
        $this->assertNotEmpty($report->highlights);
    }

    public function testCollectFillsInWhatTrimmedEventPayloadsLeaveOut(): void
    {
        // The shapes the events feed serves now: no titles, no commit lists.
        $events = [
            ['type' => 'PullRequestReviewEvent', 'public' => true, 'created_at' => '2026-09-27T16:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP'],
                'payload' => ['action' => 'created', 'review' => ['state' => 'changes_requested'], 'pull_request' => ['number' => 1414]]],
            ['type' => 'PullRequestEvent', 'public' => true, 'created_at' => '2026-09-27T15:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP'],
                'payload' => ['action' => 'opened', 'number' => 1495, 'pull_request' => ['number' => 1495]]],
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T14:00:00Z', 'repo' => ['name' => 'Valgorithms/DiscordPHP-NHA'],
                'payload' => ['ref' => 'refs/heads/main', 'before' => 'aaa1111', 'head' => 'bbb2222']],
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T13:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP.org'],
                'payload' => ['ref' => 'refs/heads/main', 'before' => '0000000000000000000000000000000000000000', 'head' => 'ccc3333']],
        ];
        $http = self::fakeHttp([
            '/pulls/1414' => ['number' => 1414, 'title' => 'Normalize options', 'merged' => false],
            '/pulls/1495' => ['number' => 1495, 'title' => 'Register the scheduled event exception handlers', 'merged' => false],
            '/compare/aaa1111...bbb2222' => ['total_commits' => 2, 'commits' => [
                ['sha' => 'd1', 'commit' => ['message' => "Teach the planner to mine\n\nbody"]],
                ['sha' => 'd2', 'commit' => ['message' => 'Fix the relay']],
            ]],
            '/events' => $events,
            '/search/commits' => ['items' => []],
        ], $log);

        $report = self::settle((new GitHubSource($http, 'valzargaming'))->collect($this->window()));

        $this->assertSame([
            '[discord-php/DiscordPHP.org] pushed to main',
            '[Valgorithms/DiscordPHP-NHA] pushed 2 commits to main: Teach the planner to mine; Fix the relay',
            '[discord-php/DiscordPHP] opened pull request #1495 "Register the scheduled event exception handlers"',
            '[discord-php/DiscordPHP] reviewed pull request #1414 "Normalize options" (changes requested)',
        ], $report->highlights);
        $this->assertSame(2, $report->stats['commits']);
        $this->assertEmpty(array_filter($log, static fn($u) => str_contains($u, '0000000')), 'a new-branch push has nothing to compare');
    }

    public function testCollectReportsApiFailuresAsErrors(): void
    {
        $http = self::fakeHttp(['/events' => [401, '{"message":"Bad credentials"}'], '/search/commits' => ['items' => []]]);
        $report = self::settle((new GitHubSource($http, 'valzargaming', 'bad'))->collect($this->window()));

        $this->assertStringContainsString('Bad credentials', $report->errors[0]);
    }
}
