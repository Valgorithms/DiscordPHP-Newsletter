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
            // A branch that had master merged in: other people's and older commits, plus the merge itself.
            '/compare/aaa1111...bbb2222' => ['total_commits' => 5, 'commits' => [
                ['sha' => 'u1', 'author' => ['login' => 'someone-else'], 'parents' => [[]], 'commit' => ['message' => 'Upstream work', 'author' => ['date' => '2026-09-27T10:00:00Z']]],
                ['sha' => 'o1', 'author' => ['login' => 'ValZarGaming'], 'parents' => [[]], 'commit' => ['message' => 'Last week', 'author' => ['date' => '2026-09-20T10:00:00Z']]],
                ['sha' => 'm1', 'author' => ['login' => 'valzargaming'], 'parents' => [[], []], 'commit' => ['message' => "Merge branch 'master'", 'author' => ['date' => '2026-09-27T13:00:00Z']]],
                ['sha' => 'd1', 'author' => ['login' => 'valzargaming'], 'parents' => [[]], 'commit' => ['message' => "Teach the planner to mine\n\nbody", 'author' => ['date' => '2026-09-27T13:10:00Z']]],
                ['sha' => 'd2', 'author' => ['login' => 'valzargaming'], 'parents' => [[]], 'commit' => ['message' => 'Fix the relay', 'author' => ['date' => '2026-09-27T13:20:00Z']]],
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

    public function testLeavesOutTheCommitsThatPublishTheNewsletter(): void
    {
        $source = new GitHubSource(self::fakeHttp([]), 'valzargaming', publishTargets: [['repo' => 'discord-php/DiscordPHP.org', 'branch' => 'main']]);
        $events = [
            // Only the publisher's commit: no line at all.
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T20:00:00Z', 'repo' => ['name' => 'valzargaming/valgorithms.com'],
                'payload' => ['ref' => 'refs/heads/main', 'size' => 1, 'commits' => [['sha' => 'n1', 'message' => 'Newsletter: 2026-09-26 — Polls land']]]],
            // A push to a publish target whose commits are unknown: most likely the publisher, so left out.
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T19:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP.org'],
                'payload' => ['ref' => 'refs/heads/main', 'before' => 'a', 'head' => 'b']],
            // Real work mixed with a publish commit: only the work is counted.
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T18:00:00Z', 'repo' => ['name' => 'discord-php/DiscordPHP.org'],
                'payload' => ['ref' => 'refs/heads/main', 'size' => 2, 'distinct_size' => 2, 'commits' => [
                    ['sha' => 'w1', 'message' => 'Add the newsletter page', 'distinct' => true],
                    ['sha' => 'n2', 'message' => 'Newsletter: 2026-09-25 — Caches', 'distinct' => true],
                ]]],
        ];
        $commits = [
            ['sha' => 'n3', 'commit' => ['message' => 'Newsletter: 2026-09-27 — Today', 'author' => ['date' => '2026-09-27T21:00:00Z']], 'repository' => ['full_name' => 'discord-php/DiscordPHP.org', 'private' => false]],
        ];

        $report = $source->report($this->window(), $events, $commits);

        $this->assertSame(['[discord-php/DiscordPHP.org] pushed 1 commit to main: Add the newsletter page'], $report->highlights);
        $this->assertSame(1, $report->stats['commits']);
        $this->assertStringNotContainsString('Newsletter:', $report->toPromptText());
    }

    public function testOfTheBotsOwnRepositoryKeepsOnlyCommitsThatChangeWhatItDoes(): void
    {
        $self = 'Valgorithms/DiscordPHP-Newsletter';
        $events = [
            ['type' => 'PushEvent', 'public' => true, 'created_at' => '2026-09-27T18:00:00Z', 'repo' => ['name' => $self],
                'payload' => ['ref' => 'refs/heads/main', 'size' => 4, 'commits' => [
                    ['sha' => 'f1', 'message' => 'Post approved editions to Telegram channels', 'distinct' => true],
                    ['sha' => 'r1', 'message' => "Document Reddit's bot-account requirement", 'distinct' => true],
                    ['sha' => 'c1', 'message' => 'Quote .env values containing spaces', 'distinct' => true],
                    ['sha' => 't1', 'message' => 'Cover the Telegram publisher', 'distinct' => true],
                ]]],
            ['type' => 'IssuesEvent', 'public' => true, 'created_at' => '2026-09-27T17:00:00Z', 'repo' => ['name' => $self],
                'payload' => ['action' => 'opened', 'issue' => ['number' => 3, 'title' => 'Typo in README']]],
            ['type' => 'ReleaseEvent', 'public' => true, 'created_at' => '2026-09-27T16:00:00Z', 'repo' => ['name' => $self],
                'payload' => ['action' => 'published', 'release' => ['name' => 'v1.1.0']]],
        ];
        $http = self::fakeHttp([
            '/commits/f1' => ['files' => [['filename' => 'src/Newsletter/TelegramPublisher.php'], ['filename' => 'README.md']]],
            '/commits/c1' => ['files' => [['filename' => 'env.example'], ['filename' => 'README.md']]],
            '/commits/t1' => ['files' => [['filename' => 'tests/TelegramPublisherTest.php']]],
            '/events' => $events,
            '/search/commits' => ['items' => [
                ['sha' => 'f2', 'commit' => ['message' => 'Read the earlier editions before writing', 'author' => ['date' => '2026-09-27T19:00:00Z']], 'repository' => ['full_name' => $self, 'private' => false]],
            ]],
            '/commits/f2' => ['files' => [['filename' => 'src/Newsletter/Writer.php']]],
        ], $log);

        $report = self::settle((new GitHubSource($http, 'valzargaming', selfRepo: $self))->collect($this->window()));

        $this->assertSame([
            "[{$self}, this newsletter's own bot] pushed 1 commit to main: Post approved editions to Telegram channels",
            "[{$self}, this newsletter's own bot] commits: Read the earlier editions before writing (f2)",
        ], $report->highlights);
        $this->assertSame(2, $report->stats['commits']);
        $this->assertEmpty(array_filter($log, static fn($u) => str_contains($u, '/commits/r1')), 'a routine message needs no lookup');
    }

    public function testTellsRoutineCommitsAndSourceFilesApart(): void
    {
        foreach (['docs: explain the window', 'chore(deps): bump guzzle', 'Merge branch main', 'Document the Reddit setup', 'Fix typos in the README', 'Apply php-cs-fixer', 'Update the tests', 'Add tests for the writer'] as $routine) {
            $this->assertTrue(GitHubSource::isRoutine($routine), $routine);
        }
        foreach (['feat: post to Telegram', 'Fix the relay', 'Skip the newsletter channel when recording'] as $work) {
            $this->assertFalse(GitHubSource::isRoutine($work), $work);
        }
        foreach (['src/Newsletter/Writer.php', 'bot.php', 'devvit/src/main.ts'] as $source) {
            $this->assertTrue(GitHubSource::isSourceFile($source), $source);
        }
        foreach (['tests/WriterTest.php', 'README.md', 'env.example', '.php-cs-fixer.dist.php', '.github/workflows/ci.yml', 'composer.lock', 'devvit/src/main.test.ts'] as $other) {
            $this->assertFalse(GitHubSource::isSourceFile($other), $other);
        }
    }

    public function testCollectReportsApiFailuresAsErrors(): void
    {
        $http = self::fakeHttp(['/events' => [401, '{"message":"Bad credentials"}'], '/search/commits' => ['items' => []]]);
        $report = self::settle((new GitHubSource($http, 'valzargaming', 'bad'))->collect($this->window()));

        $this->assertStringContainsString('Bad credentials', $report->errors[0]);
    }
}
