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

use Newsletter\Draft;
use Newsletter\RedditPublisher;
use Newsletter\Window;

final class RedditPublisherTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-09-26T18:00:00-04:00'), new \DateTimeImmutable('2026-09-27T18:00:00-04:00'));
    }

    /** @return array<string, mixed> */
    private function edition(array $reddit = []): array
    {
        return ['key' => '2026-09-27', 'reddit' => $reddit, 'draft' => [
            'headline' => 'Deep Dives and Website Updates',
            'intro' => 'Reviewed discord-php/DiscordPHP#1414 and `discord-php/DiscordPHP#1416`.',
            'sections' => [['title' => 'Web', 'body' => 'Launched the site.']],
            'signoff' => 'See you tomorrow!',
        ]];
    }

    private function publisher($http, array $targets = ['r/ValZarGaming', 'u/valzargaming'], ?string $refresh = 'refresh', ?string $password = null): RedditPublisher
    {
        return new RedditPublisher($http, $targets, 'client', 'secret', 'valzargaming', $refresh, $password, 'flair-1');
    }

    public function testNormalizesTargetsTheWayPeopleWriteThem(): void
    {
        foreach ([
            'r/ValZarGaming' => 'ValZarGaming', '/r/ValZarGaming/' => 'ValZarGaming', 'ValZarGaming' => 'ValZarGaming',
            'u/valzargaming' => 'u_valzargaming', 'https://www.reddit.com/user/valzargaming/' => 'u_valzargaming', 'u_valzargaming' => 'u_valzargaming',
        ] as $input => $api) {
            $this->assertSame($api, RedditPublisher::normalize($input), $input);
        }
        $this->assertSame('u/valzargaming', RedditPublisher::display('u_valzargaming'));
        $this->assertSame('r/ValZarGaming', RedditPublisher::display('ValZarGaming'));
    }

    public function testBodyLinksGitHubReferencesAndTitleCarriesTheDate(): void
    {
        $draft = Draft::fromArray($this->edition()['draft']);

        $body = RedditPublisher::body($draft, 'Also on https://discordphp.org/newsletter.html');

        $this->assertStringContainsString('[discord-php/DiscordPHP#1414](https://github.com/discord-php/DiscordPHP/issues/1414)', $body);
        $this->assertStringContainsString('`discord-php/DiscordPHP#1416`', $body, 'code spans stay as they are');
        $this->assertStringContainsString("## Web\n\nLaunched the site.", $body);
        $this->assertStringContainsString('*See you tomorrow!*', $body);
        $this->assertStringEndsWith('^(Also on https://discordphp.org/newsletter.html)', $body);
        $this->assertStringNotContainsString('Deep Dives', $body, 'the headline is the title');
        $this->assertSame('Deep Dives and Website Updates (Sep 27, 2026)', RedditPublisher::title($draft, $this->window()));
    }

    public function testPostsToASubredditAndTheProfileSigningInOnce(): void
    {
        $n = 0;
        $http = self::fakeHttp([
            'POST /api/v1/access_token' => ['access_token' => 'tok', 'expires_in' => 3600],
            'POST /api/submit' => function () use (&$n) {
                $n++;

                return ['json' => ['errors' => [], 'data' => ['name' => "t3_{$n}", 'url' => "https://www.reddit.com/p/{$n}"]]];
            },
        ], $log, $payloads);

        $results = self::settle($this->publisher($http)->publish($this->edition(), $this->window(), 'footer'));

        $this->assertSame([
            'ValZarGaming' => ['id' => 't3_1', 'url' => 'https://www.reddit.com/p/1', 'edited' => false],
            'u_valzargaming' => ['id' => 't3_2', 'url' => 'https://www.reddit.com/p/2', 'edited' => false],
        ], $results);
        $this->assertCount(1, array_filter($log, static fn($u) => str_contains($u, 'access_token')), 'one sign-in for both posts');

        [$auth, $sub, $profile] = $payloads;
        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh'], array_diff_key($auth, ['__headers' => 1]));
        $this->assertSame('Basic ' . base64_encode('client:secret'), $auth['__headers']['Authorization']);
        $this->assertSame('php:DiscordPHP-Newsletter:1.0 (by /u/valzargaming)', $auth['__headers']['User-Agent']);
        $this->assertSame(['ValZarGaming', 'self', 'flair-1'], [$sub['sr'], $sub['kind'], $sub['flair_id']]);
        $this->assertSame('Bearer tok', $sub['__headers']['Authorization']);
        $this->assertSame('u_valzargaming', $profile['sr']);
        $this->assertArrayNotHasKey('flair_id', $profile, 'profiles have no flair');
    }

    public function testARetryEditsExistingPostsAndReportsErrorsPerTarget(): void
    {
        $http = self::fakeHttp([
            'POST /api/v1/access_token' => ['access_token' => 'tok'],
            'POST /api/editusertext' => ['json' => ['errors' => []]],
            'POST /api/submit' => ['json' => ['errors' => [['SUBREDDIT_NOTALLOWED', 'you aren\'t allowed to post there.', 'sr']]]],
        ], $log, $payloads);

        $results = self::settle($this->publisher($http)->publish(
            $this->edition(['ValZarGaming' => ['id' => 't3_old', 'url' => 'https://www.reddit.com/old']]),
            $this->window(),
        ));

        $this->assertSame(['id' => 't3_old', 'url' => 'https://www.reddit.com/old', 'edited' => true], $results['ValZarGaming']);
        $this->assertSame('t3_old', $payloads[1]['thing_id']);
        $this->assertSame(['error' => "Reddit said: you aren't allowed to post there. (SUBREDDIT_NOTALLOWED)"], $results['u_valzargaming']);
    }

    public function testSignsInWithThePasswordWithoutARefreshTokenAndExplainsARefusal(): void
    {
        $http = self::fakeHttp(['POST /api/v1/access_token' => [401, '{"error": "invalid_grant"}']], $log, $payloads);

        $results = self::settle($this->publisher($http, ['r/ValZarGaming'], null, 'hunter2')->publish($this->edition(), $this->window()));

        $this->assertSame(['grant_type' => 'password', 'username' => 'valzargaming', 'password' => 'hunter2'], array_diff_key($payloads[0], ['__headers' => 1]));
        $this->assertStringStartsWith('Could not sign in to Reddit: HTTP 401', $results['ValZarGaming']['error']);
        $this->assertStringContainsString('invalid_grant', $results['ValZarGaming']['error']);
    }

    public function testCheckReportsWhatWouldStopAPost(): void
    {
        $http = self::fakeHttp([
            'POST /api/v1/access_token' => ['access_token' => 'tok'],
            'GET /r/ValZarGaming/about' => ['data' => ['user_is_moderator' => true, 'submission_type' => 'any']],
            'GET /r/u_valzargaming/about' => ['data' => ['user_is_moderator' => true]],
            'GET /r/Banned/about' => ['data' => ['user_is_banned' => true]],
        ]);

        $this->assertSame([], self::settle($this->publisher($http)->check()));
        $this->assertSame([
            'u/someoneelse: only u/valzargaming\'s own profile can be posted to',
            'r/Banned: u/valzargaming is banned there',
        ], self::settle($this->publisher($http, ['u/someoneelse', 'r/Banned'])->check()));
        $this->assertSame(
            ['r/ValZarGaming: set REDDIT_REFRESH_TOKEN (or REDDIT_PASSWORD) to let the bot sign in to Reddit'],
            self::settle($this->publisher($http, ['r/ValZarGaming'], null, null)->check()),
        );
    }
}
