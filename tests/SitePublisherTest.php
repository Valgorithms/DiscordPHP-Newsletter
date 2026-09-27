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

use Newsletter\SitePublisher;
use Newsletter\Window;

final class SitePublisherTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-09-26T18:00:00-04:00'), new \DateTimeImmutable('2026-09-27T18:00:00-04:00'));
    }

    /** @return array<string, mixed> */
    private function edition(string $headline = 'Three PRs merged'): array
    {
        return ['key' => '2026-09-27', 'draft' => ['headline' => $headline, 'intro' => 'Busy day.', 'sections' => [['title' => 'Code', 'body' => '- merged #1494']], 'signoff' => 'Bye']];
    }

    private static function file(array $doc, string $sha = 'abc'): array
    {
        return ['sha' => $sha, 'content' => chunk_split(base64_encode(json_encode($doc)), 60, "\n")];
    }

    public function testParsesTargets(): void
    {
        $this->assertSame([
            ['repo' => 'discord-php/DiscordPHP.org', 'branch' => 'main', 'path' => 'data/newsletter.json'],
            ['repo' => 'valzargaming/valgorithms.com', 'branch' => 'gh-src', 'path' => 'site/data/newsletter.json'],
        ], SitePublisher::parseTargets('discord-php/DiscordPHP.org:data/newsletter.json, valzargaming/valgorithms.com@gh-src:site/data/newsletter.json'));
        $this->assertSame([], SitePublisher::parseTargets(''));

        $this->expectException(\InvalidArgumentException::class);
        SitePublisher::parseTargets('not a target');
    }

    public function testUpsertReplacesTheSameEditionAndKeepsNewestFirst(): void
    {
        $doc = ['editions' => [['key' => '2026-09-26', 'date' => '2026-09-26'], ['key' => '2026-09-27', 'date' => '2026-09-27', 'headline' => 'old']]];

        $out = SitePublisher::upsert($doc, ['key' => '2026-09-27', 'date' => '2026-09-27', 'headline' => 'new']);

        $this->assertSame(['2026-09-27', '2026-09-26'], array_column($out['editions'], 'key'));
        $this->assertSame('new', $out['editions'][0]['headline']);
    }

    public function testCreatesTheFileWhenTheSiteHasNone(): void
    {
        $http = self::fakeHttp([
            'GET /contents/data/newsletter.json?ref=main' => [404, '{"message":"Not Found"}'],
            'PUT /contents/data/newsletter.json' => ['commit' => ['html_url' => 'https://github.com/discord-php/DiscordPHP.org/commit/1']],
        ], $log, $payloads);
        $publisher = new SitePublisher($http, SitePublisher::parseTargets('discord-php/DiscordPHP.org:data/newsletter.json'), static fn() => 'tkn');

        $lines = self::settle($publisher->publish($this->edition(), $this->window()));

        $this->assertSame(['discord-php/DiscordPHP.org: https://github.com/discord-php/DiscordPHP.org/commit/1'], $lines);
        $this->assertArrayNotHasKey('sha', $payloads[0]);
        $this->assertSame('main', $payloads[0]['branch']);
        $this->assertSame('Newsletter: 2026-09-27 — Three PRs merged', $payloads[0]['message']);
        $written = json_decode(base64_decode($payloads[0]['content']), true);
        $this->assertSame('2026-09-27', $written['editions'][0]['date']);
        $this->assertSame([['title' => 'Code', 'body' => '- merged #1494']], $written['editions'][0]['sections']);
    }

    public function testUpdatesAnExistingFileAndRetriesOnceOnAConflict(): void
    {
        $puts = 0;
        $http = self::fakeHttp([
            'GET /contents/' => self::file(['editions' => [['key' => '2026-09-26', 'date' => '2026-09-26', 'headline' => 'Saturday']]], 'sha1'),
            'PUT /contents/' => function () use (&$puts) {
                return ++$puts === 1 ? [409, '{"message":"is at sha2 but expected sha1"}'] : ['commit' => ['html_url' => 'https://example/commit/2']];
            },
        ], $log, $payloads);
        $publisher = new SitePublisher($http, SitePublisher::parseTargets('valzargaming/valgorithms.com:site/data/newsletter.json'), static fn() => 'tkn');

        $lines = self::settle($publisher->publish($this->edition(), $this->window()));

        $this->assertSame(['valzargaming/valgorithms.com: https://example/commit/2'], $lines);
        $this->assertSame(2, $puts);
        $this->assertSame('sha1', $payloads[1]['sha']);
        $this->assertSame(['2026-09-27', '2026-09-26'], array_column(json_decode(base64_decode($payloads[1]['content']), true)['editions'], 'key'));
    }

    public function testOneFailingSiteDoesNotStopTheOthers(): void
    {
        $http = self::fakeHttp(['GET /repos/b/site/contents/' => [404, '{}'], 'PUT /repos/b/site/contents/' => ['commit' => ['html_url' => 'https://b/commit']]]);
        $publisher = new SitePublisher($http, SitePublisher::parseTargets('a/site:x.json,b/site:x.json'), static fn(string $owner) => $owner === 'b' ? 'tkn' : null);

        $this->assertSame([
            'a/site: failed (no token for a; set PUBLISH_GITHUB_TOKEN)',
            'b/site: https://b/commit',
        ], self::settle($publisher->publish($this->edition(), $this->window())));
    }
}
