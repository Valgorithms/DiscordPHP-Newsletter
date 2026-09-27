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

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Posts approved editions to Telegram channels and groups through the Bot API,
 * as a bot that is an admin of each (for channels, one allowed to post).
 *
 * The newsletter is sent as Telegram HTML, split at paragraph boundaries when it
 * is longer than one message allows. An edition published again edits its
 * messages in place (sending or deleting the difference when the split changes),
 * so a retry never posts it twice.
 *
 * @link https://core.telegram.org/bots/api#sendmessage
 * @link https://core.telegram.org/bots/api#formatting-options
 *
 * @since 1.0.0
 */
final class TelegramPublisher
{
    private const API = 'https://api.telegram.org/bot';

    /** Telegram's limit on a message's text, after entity parsing; kept under for safety. */
    public const MAX_MESSAGE = 4000;

    private readonly LoggerInterface $logger;

    /** @var list<string> Chat ids (`-100…`) or public usernames (`@name`). */
    private readonly array $chats;

    /**
     * @param list<string> $chats `@channelname` or a numeric chat id per target.
     * @param string       $token The bot's token from @BotFather.
     */
    public function __construct(
        private readonly JsonClient $http,
        array $chats,
        private readonly string $token,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->chats = array_values(array_unique(array_map(self::normalize(...), $chats)));
    }

    /** `@name` for a public username (with or without the `@`, or a t.me link), else the id as given. */
    public static function normalize(string $chat): string
    {
        $chat = trim((string) preg_replace('#^(https?://)?(t\.me|telegram\.me)/#i', '', trim($chat)), '/');

        return preg_match('/^-?\d+$/', $chat) || str_starts_with($chat, '@') ? $chat : "@{$chat}";
    }

    /** @return list<string> Every chat, for messages. */
    public function describe(): array
    {
        return array_map(static fn(string $c) => "Telegram {$c}", $this->chats);
    }

    /**
     * Checks the token works and the bot may post in every chat. Resolves with
     * one problem per line; an empty list means every chat is ready.
     *
     * @return PromiseInterface<list<string>>
     */
    public function check(): PromiseInterface
    {
        return $this->call('getMe')->then(function (array $me): PromiseInterface {
            $botId = (int) ($me['id'] ?? 0);
            $checks = array_map(fn(string $chat): PromiseInterface => $this->call('getChatMember', ['chat_id' => $chat, 'user_id' => $botId])->then(
                static function (array $member) use ($chat): ?string {
                    $status = (string) ($member['status'] ?? '');
                    if (! in_array($status, ['administrator', 'creator', 'member'], true)) {
                        return "Telegram {$chat}: the bot is not in it (status: {$status})";
                    }
                    if ($status === 'administrator' && array_key_exists('can_post_messages', $member) && ! $member['can_post_messages']) {
                        return "Telegram {$chat}: the bot is an admin but may not post messages";
                    }

                    return null;
                },
                static fn(\Throwable $e): string => "Telegram {$chat}: {$e->getMessage()}",
            ), $this->chats);

            return all($checks)->then(static fn(array $problems): array => array_values(array_filter($problems)));
        }, static fn(\Throwable $e): array => ["Telegram: the bot token was refused ({$e->getMessage()})"]);
    }

    /**
     * Posts the edition to every chat, one after another, editing its messages
     * where it was posted before. Never rejects: resolves with the outcome per
     * chat, keyed by chat.
     *
     * @param array<string, mixed> $edition `telegram` holds earlier posts, keyed by chat: `{message_ids, url}`.
     *
     * @return PromiseInterface<array<string, array{message_ids?: list<int>, url?: string, edited?: bool, error?: string}>>
     */
    public function publish(array $edition, Window $window, string $footer = ''): PromiseInterface
    {
        $parts = self::split(self::html(Draft::fromArray((array) $edition['draft']), $window, $footer));
        $results = [];
        $chain = resolve(null);
        foreach ($this->chats as $chat) {
            $chain = $chain->then(function () use ($chat, $parts, $edition, &$results): PromiseInterface {
                $existing = array_map('intval', (array) ($edition['telegram'][$chat]['message_ids'] ?? []));

                return $this->publishTo($chat, $parts, $existing)->then(
                    function (array $post) use ($chat, &$results): void {
                        $results[$chat] = $post;
                    },
                    function (\Throwable $e) use ($chat, &$results): void {
                        $this->logger->warning("Posting to Telegram {$chat} failed: {$e->getMessage()}");
                        $results[$chat] = ['error' => $e->getMessage()];
                    },
                );
            });
        }

        return $chain->then(function () use (&$results): array {
            return $results;
        });
    }

    /**
     * The newsletter as Telegram HTML: everything escaped, then bold, italics,
     * inline code and links applied, headings made bold, and bare
     * `owner/repo#123` references linked to GitHub.
     */
    public static function html(Draft $draft, Window $window, string $footer = ''): string
    {
        $blocks = ['<b>' . self::inline($draft->headline) . '</b>' . "\n<i>" . self::escape($window->label()) . '</i>'];
        if ($draft->intro !== '') {
            $blocks[] = self::markdown($draft->intro);
        }
        foreach ($draft->sections as $section) {
            $blocks[] = ($section['title'] !== '' ? '<b>' . self::inline($section['title']) . "</b>\n" : '') . self::markdown($section['body']);
        }
        if ($draft->signoff !== '') {
            $blocks[] = '<i>' . self::inline($draft->signoff) . '</i>';
        }
        if ($footer !== '') {
            $blocks[] = self::inline($footer);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Splits HTML into messages no longer than {@see MAX_MESSAGE}, between
     * paragraphs (then lines) so no tag is ever cut in half.
     *
     * @return list<string>
     */
    public static function split(string $html): array
    {
        $parts = [];
        $current = '';
        foreach (preg_split("/\n\n/", $html) ?: [] as $paragraph) {
            foreach (mb_strlen($paragraph) > self::MAX_MESSAGE ? explode("\n", $paragraph) : [$paragraph] as $piece) {
                $glue = $current === '' ? '' : "\n\n";
                if ($current !== '' && mb_strlen($current . $glue . $piece) > self::MAX_MESSAGE) {
                    $parts[] = $current;
                    $current = '';
                    $glue = '';
                }
                // A single line longer than a message is cut, as plain text so no tag breaks.
                while (mb_strlen($piece) > self::MAX_MESSAGE) {
                    $plain = html_entity_decode(strip_tags($piece), ENT_QUOTES | ENT_HTML5);
                    $parts[] = self::escape(mb_substr($plain, 0, self::MAX_MESSAGE - 1)) . '…';
                    $piece = self::escape(mb_substr($plain, self::MAX_MESSAGE - 1));
                }
                $current .= $glue . $piece;
            }
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @param list<string> $parts
     * @param list<int>    $existing Message ids from an earlier publish, in order.
     *
     * @return PromiseInterface<array{message_ids: list<int>, url: string, edited: bool}>
     */
    private function publishTo(string $chat, array $parts, array $existing): PromiseInterface
    {
        $ids = [];
        $chain = resolve(null);
        foreach ($parts as $i => $text) {
            $chain = $chain->then(function () use ($chat, $text, $i, $existing, &$ids): PromiseInterface {
                $options = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
                if (isset($existing[$i])) {
                    return $this->call('editMessageText', $options + ['message_id' => $existing[$i]])->then(
                        function () use ($existing, $i, &$ids): void {
                            $ids[] = $existing[$i];
                        },
                        function (\Throwable $e) use ($existing, $i, &$ids): void {
                            // Editing to identical text is refused; the message is already right.
                            if (! str_contains($e->getMessage(), 'message is not modified')) {
                                throw $e;
                            }
                            $ids[] = $existing[$i];
                        },
                    );
                }

                return $this->call('sendMessage', $options)->then(function (array $message) use (&$ids): void {
                    $ids[] = (int) $message['message_id'];
                });
            });
        }

        return $chain->then(function () use ($chat, $existing, &$ids): PromiseInterface {
            // The edition is shorter than last time: drop the messages it no longer needs.
            $extra = array_slice($existing, count($ids));
            $deletes = array_map(fn(int $id) => $this->call('deleteMessage', ['chat_id' => $chat, 'message_id' => $id])->then(null, static fn() => null), $extra);

            return all($deletes)->then(fn(): array => [
                'message_ids' => $ids,
                'url' => self::link($chat, $ids[0] ?? 0),
                'edited' => $existing !== [],
            ]);
        });
    }

    /** A t.me link to a message in a public chat; private chats have none. */
    private static function link(string $chat, int $messageId): string
    {
        return str_starts_with($chat, '@') && $messageId > 0 ? 'https://t.me/' . substr($chat, 1) . "/{$messageId}" : '';
    }

    /**
     * Calls a Bot API method and resolves with its `result`.
     *
     * @param array<string, mixed> $params
     *
     * @return PromiseInterface<array<mixed>>
     */
    private function call(string $method, array $params = []): PromiseInterface
    {
        return $this->http->request('POST', self::API . $this->token . "/{$method}", [], $params)
            ->then(static function (array $body) use ($method): array {
                if (! ($body['ok'] ?? false)) {
                    throw new \RuntimeException("Telegram {$method} failed: " . ($body['description'] ?? 'no reason given'));
                }

                return is_array($body['result'] ?? null) ? $body['result'] : ['value' => $body['result'] ?? null];
            }, static function (\Throwable $e) use ($method): never {
                // Never let the token (part of the URL) reach a log or a DM.
                throw new \RuntimeException("Telegram {$method}: " . preg_replace('#/bot[^/\s]+/#', '/bot***/', $e->getMessage()), (int) $e->getCode(), $e);
            });
    }

    /** Paragraphs of Markdown to Telegram HTML; bullets become "•". */
    private static function markdown(string $text): string
    {
        $lines = array_map(static function (string $line): string {
            if (preg_match('/^\s*[-*•]\s+(.*)$/', $line, $m)) {
                return '• ' . self::inline($m[1]);
            }
            if (preg_match('/^#{1,6}\s+(.*)$/', $line, $m)) {
                return '<b>' . self::inline($m[1]) . '</b>';
            }

            return self::inline($line);
        }, explode("\n", $text));

        return trim(implode("\n", $lines));
    }

    /** One line of Markdown to Telegram HTML, escaping everything else. */
    private static function inline(string $text): string
    {
        $codes = [];
        $html = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::escape($m[1]) . '</code>';

            return "\u{0}" . (count($codes) - 1) . "\u{0}";
        }, $text);
        $html = self::escape($html);
        $html = (string) preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $html);
        $html = (string) preg_replace('/(?<![\w\/"=>])([A-Za-z0-9-]+\/[A-Za-z0-9._-]+)#(\d+)\b/', '<a href="https://github.com/$1/issues/$2">$1#$2</a>', $html);
        $html = (string) preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $html);
        $html = (string) preg_replace('/(^|[^*\w])\*([^*\s][^*]*)\*(?!\w)/', '$1<i>$2</i>', $html);

        return (string) preg_replace_callback("/\u{0}(\\d+)\u{0}/", static fn(array $m): string => $codes[(int) $m[1]], $html);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

}
