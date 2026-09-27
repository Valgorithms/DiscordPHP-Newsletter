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
use Newsletter\TelegramPublisher;
use Newsletter\Window;

final class TelegramPublisherTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-09-26T18:00:00-04:00'), new \DateTimeImmutable('2026-09-27T18:00:00-04:00'));
    }

    private function draft(string $body = 'Launched the <site> & more.'): Draft
    {
        return Draft::fromArray([
            'headline' => 'Deep Dives & Website Updates',
            'intro' => "Reviewed discord-php/DiscordPHP#1414, **merged** three PRs and fixed `a<b`.\n- one\n- two",
            'sections' => [['title' => 'Web', 'body' => $body . ' See [the site](https://discordphp.org).']],
            'signoff' => 'See you tomorrow!',
        ]);
    }

    public function testNormalizesChats(): void
    {
        foreach (['@DiscordPHP' => '@DiscordPHP', 'DiscordPHP' => '@DiscordPHP', 'https://t.me/DiscordPHP' => '@DiscordPHP', '-1001234567890' => '-1001234567890'] as $in => $out) {
            $this->assertSame($out, TelegramPublisher::normalize((string) $in), (string) $in);
        }
    }

    public function testRendersEscapedTelegramHtml(): void
    {
        $html = TelegramPublisher::html($this->draft(), $this->window(), 'Also on https://discordphp.org/newsletter.html');

        $this->assertStringStartsWith("<b>Deep Dives &amp; Website Updates</b>\n<i>Sunday, September 27, 2026</i>", $html);
        $this->assertStringContainsString('<a href="https://github.com/discord-php/DiscordPHP/issues/1414">discord-php/DiscordPHP#1414</a>', $html);
        $this->assertStringContainsString('<b>merged</b>', $html);
        $this->assertStringContainsString('<code>a&lt;b</code>', $html);
        $this->assertStringContainsString("• one\n• two", $html);
        $this->assertStringContainsString("<b>Web</b>\nLaunched the &lt;site&gt; &amp; more. See <a href=\"https://discordphp.org\">the site</a>.", $html);
        $this->assertStringContainsString('<i>See you tomorrow!</i>', $html);
        $this->assertStringEndsWith('Also on https://discordphp.org/newsletter.html', $html);
    }

    public function testSplitsLongNewslettersBetweenParagraphs(): void
    {
        $paragraph = str_repeat('word ', 300); // 1500 characters
        $parts = TelegramPublisher::split(implode("\n\n", array_fill(0, 6, "<b>x</b> {$paragraph}")));

        $this->assertCount(3, $parts);
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(TelegramPublisher::MAX_MESSAGE, mb_strlen($part));
            $this->assertSame(substr_count($part, '<b>'), substr_count($part, '</b>'), 'no tag is cut in half');
        }
        $this->assertCount(1, TelegramPublisher::split('short'));
    }

    public function testPostsThenEditsOnARetry(): void
    {
        $id = 100;
        $http = self::fakeHttp([
            '/sendMessage' => function () use (&$id) {
                return ['ok' => true, 'result' => ['message_id' => ++$id]];
            },
            '/editMessageText' => ['ok' => true, 'result' => ['message_id' => 1]],
        ], $log, $payloads);
        $publisher = new TelegramPublisher($http, ['@DiscordPHP'], 'SECRET');
        $edition = ['key' => '2026-09-27', 'draft' => $this->draft()->toArray()];

        $first = self::settle($publisher->publish($edition, $this->window()));

        $this->assertSame(['@DiscordPHP' => ['message_ids' => [101], 'url' => 'https://t.me/DiscordPHP/101', 'edited' => false]], $first);
        $this->assertSame(['@DiscordPHP', 'HTML', true], [$payloads[0]['chat_id'], $payloads[0]['parse_mode'], $payloads[0]['link_preview_options']['is_disabled']]);
        $this->assertStringContainsString('/botSECRET/sendMessage', $log[0]);

        $edition['telegram'] = ['@DiscordPHP' => ['message_ids' => [101]]];
        $second = self::settle($publisher->publish($edition, $this->window()));

        $this->assertSame(['message_ids' => [101], 'url' => 'https://t.me/DiscordPHP/101', 'edited' => true], $second['@DiscordPHP']);
        $this->assertSame(101, $payloads[1]['message_id']);
        $this->assertCount(1, array_filter($log, static fn($u) => str_contains($u, '/sendMessage')), 'a retry sends nothing new');
    }

    public function testAnUnchangedEditCountsAsDoneAndShorterEditionsDeleteLeftovers(): void
    {
        $http = self::fakeHttp([
            '/editMessageText' => [400, '{"ok":false,"error_code":400,"description":"Bad Request: message is not modified"}'],
            '/deleteMessage' => ['ok' => true, 'result' => true],
        ], $log);
        $publisher = new TelegramPublisher($http, ['-1001234567890'], 'SECRET');
        $edition = ['key' => 'k', 'draft' => $this->draft()->toArray(), 'telegram' => ['-1001234567890' => ['message_ids' => [7, 8]]]];

        $result = self::settle($publisher->publish($edition, $this->window()))['-1001234567890'];

        $this->assertSame(['message_ids' => [7], 'url' => '', 'edited' => true], $result, 'a private chat has no public link');
        $this->assertCount(1, array_filter($log, static fn($u) => str_contains($u, '/deleteMessage')));
    }

    public function testErrorsArePerChatAndNeverShowTheToken(): void
    {
        $http = self::fakeHttp([
            'POST /botSECRET/sendMessage' => [403, '{"ok":false,"error_code":403,"description":"Forbidden: bot is not a member of the channel chat"}'],
        ]);
        $results = self::settle((new TelegramPublisher($http, ['@a', '@b'], 'SECRET'))->publish(['key' => 'k', 'draft' => $this->draft()->toArray()], $this->window()));

        $this->assertSame(['@a', '@b'], array_keys($results));
        $this->assertStringContainsString('bot is not a member of the channel chat', $results['@a']['error']);
        $this->assertStringNotContainsString('SECRET', $results['@a']['error']);
    }

    public function testCheckReportsChatsTheBotCannotPostIn(): void
    {
        $http = self::fakeHttp([
            '/getMe' => ['ok' => true, 'result' => ['id' => 42, 'username' => 'DiscordPHP_Bridge_Bot']],
            'POST /botT/getChatMember' => function (string $body) {
                $chat = json_decode($body, true)['chat_id'];

                return match ($chat) {
                    '@ok' => ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]],
                    '@readonly' => ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => false]],
                    default => ['ok' => true, 'result' => ['status' => 'left']],
                };
            },
        ]);

        $this->assertSame([
            'Telegram @readonly: the bot is an admin but may not post messages',
            'Telegram @gone: the bot is not in it (status: left)',
        ], self::settle((new TelegramPublisher($http, ['@ok', '@readonly', '@gone'], 'T'))->check()));

        $bad = self::fakeHttp(['/getMe' => [401, '{"ok":false,"description":"Unauthorized"}']]);
        $this->assertSame(['Telegram: the bot token was refused (Telegram getMe: HTTP 401 from https://api.telegram.org/bot***/getMe: Unauthorized)'], self::settle((new TelegramPublisher($bad, ['@ok'], 'T'))->check()));
    }
}
