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
 * Posts approved editions as text posts to subreddits and to the owner's own
 * profile, through Reddit's OAuth2 API as the owner's account (a "script" app).
 * In the API a profile is the subreddit `u_<username>`, so both post the same way.
 *
 * Signing in uses a refresh token when one is configured (it works with
 * two-factor authentication), else the account's username and password. An
 * edition that was already posted somewhere is edited in place there when it
 * is published again, so a retry never makes a second post.
 *
 * @link https://github.com/reddit-archive/reddit/wiki/OAuth2
 * @link https://www.reddit.com/dev/api#POST_api_submit
 *
 * @since 1.0.0
 */
final class RedditPublisher
{
    private const AUTH = 'https://www.reddit.com/api/v1/access_token';
    private const API = 'https://oauth.reddit.com';

    /** Reddit's limit on a post title. */
    private const MAX_TITLE = 300;

    private readonly LoggerInterface $logger;

    private ?string $token = null;

    private int $tokenExpires = 0;

    /** @var list<string> API names: `ValZarGaming`, or `u_valzargaming` for a profile. */
    private readonly array $targets;

    /**
     * @param list<string> $targets      Where to post: `r/Name` (or `Name`) for a subreddit, `u/name` for a profile.
     * @param string       $clientId     The script app's client id.
     * @param string       $clientSecret The script app's secret.
     * @param string       $username     The account that posts: the subreddits' moderator, and the only profile it can post to.
     * @param string|null  $refreshToken Preferred: a permanent refresh token with the `submit`, `edit`, `read` and `identity` scopes.
     * @param string|null  $password     Used when there is no refresh token (accounts without two-factor authentication).
     * @param string|null  $flairId      A post flair template id, for subreddits that require flair (not used on profiles).
     */
    public function __construct(
        private readonly JsonClient $http,
        array $targets,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $username,
        private readonly ?string $refreshToken = null,
        private readonly ?string $password = null,
        private readonly ?string $flairId = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->targets = array_values(array_unique(array_map(self::normalize(...), $targets)));
    }

    /**
     * The API name for a target: `r/Name`, `/r/Name` or `Name` → `Name`;
     * `u/name`, `/user/name`, a profile URL or `u_name` → `u_name`.
     */
    public static function normalize(string $target): string
    {
        $target = trim((string) preg_replace('#^https?://(www\.|old\.)?reddit\.com#i', '', trim($target)), '/');
        if (preg_match('#^(?:u|user)/([\w-]+)$#i', $target, $m)) {
            return 'u_' . $m[1];
        }

        return (string) preg_replace('#^r/#i', '', $target);
    }

    /** `r/Name` or `u/name`, as people write it. */
    public static function display(string $target): string
    {
        return str_starts_with($target, 'u_') ? 'u/' . substr($target, 2) : "r/{$target}";
    }

    /** @return list<string> Every target as people write it, for messages. */
    public function describe(): array
    {
        return array_map(self::display(...), $this->targets);
    }

    /**
     * Signs in and checks the account can post to every target. Resolves with
     * one problem per line; an empty list means every target is ready.
     *
     * @return PromiseInterface<list<string>>
     */
    public function check(): PromiseInterface
    {
        $problems = [];
        $chain = resolve(null);
        foreach ($this->targets as $target) {
            $chain = $chain->then(function () use ($target, &$problems): PromiseInterface {
                $where = self::display($target);
                if (str_starts_with($target, 'u_') && strcasecmp(substr($target, 2), $this->username) !== 0) {
                    $problems[] = "{$where}: only u/{$this->username}'s own profile can be posted to";

                    return resolve(null);
                }

                return $this->authorized('GET', self::API . "/r/{$target}/about")->then(
                    function (array $about) use ($where, &$problems): void {
                        $data = (array) ($about['data'] ?? []);
                        if (! empty($data['user_is_banned'])) {
                            $problems[] = "{$where}: u/{$this->username} is banned there";
                        } elseif (empty($data['user_is_moderator']) && ($data['submission_type'] ?? 'any') === 'link') {
                            $problems[] = "{$where}: only link posts are allowed, and u/{$this->username} is not a moderator";
                        }
                    },
                    function (\Throwable $e) use ($where, &$problems): void {
                        $problems[] = "{$where}: {$e->getMessage()}";
                    },
                );
            });
        }

        return $chain->then(function () use (&$problems): array {
            return $problems;
        });
    }

    /**
     * Posts the edition to every target, one after another, editing the post
     * where it already has one. Never rejects: resolves with the outcome per
     * target, keyed by API name.
     *
     * @param array<string, mixed> $edition `reddit` holds earlier posts, keyed by API name: `{id, url}`.
     *
     * @return PromiseInterface<array<string, array{id?: string, url?: string, edited?: bool, error?: string}>>
     */
    public function publish(array $edition, Window $window, string $footer = ''): PromiseInterface
    {
        $results = [];
        $chain = resolve(null);
        foreach ($this->targets as $target) {
            $chain = $chain->then(function () use ($edition, $window, $footer, $target, &$results): PromiseInterface {
                return $this->publishTo($target, $edition, $window, $footer)->then(
                    function (array $post) use ($target, &$results): void {
                        $results[$target] = $post;
                    },
                    function (\Throwable $e) use ($target, &$results): void {
                        $this->logger->warning('Posting to ' . self::display($target) . " failed: {$e->getMessage()}");
                        $results[$target] = ['error' => $e->getMessage()];
                    },
                );
            });
        }

        return $chain->then(function () use (&$results): array {
            return $results;
        });
    }

    /**
     * @param array<string, mixed> $edition
     *
     * @return PromiseInterface<array{id: string, url: string, edited: bool}>
     */
    private function publishTo(string $target, array $edition, Window $window, string $footer): PromiseInterface
    {
        $draft = Draft::fromArray((array) $edition['draft']);
        $body = self::body($draft, $footer);
        $existing = (array) ($edition['reddit'][$target] ?? []);

        if (! empty($existing['id'])) {
            return $this->authorized('POST', self::API . '/api/editusertext', ['api_type' => 'json', 'thing_id' => $existing['id'], 'text' => $body])
                ->then(static function (array $response) use ($existing): array {
                    self::throwErrors($response);

                    return ['id' => (string) $existing['id'], 'url' => (string) ($existing['url'] ?? ''), 'edited' => true];
                });
        }

        $fields = [
            'api_type' => 'json',
            'sr' => $target,
            'kind' => 'self',
            'title' => self::title($draft, $window),
            'text' => $body,
            'resubmit' => 'true',
            'sendreplies' => 'true',
        ];
        if ($this->flairId && ! str_starts_with($target, 'u_')) {
            $fields['flair_id'] = $this->flairId;
        }

        return $this->authorized('POST', self::API . '/api/submit', $fields)
            ->then(static function (array $response): array {
                self::throwErrors($response);
                $data = (array) ($response['json']['data'] ?? []);
                if (empty($data['name'])) {
                    throw new \RuntimeException('Reddit accepted the post but did not say where it is');
                }

                return ['id' => (string) $data['name'], 'url' => (string) ($data['url'] ?? ''), 'edited' => false];
            });
    }

    /** The post title: the headline, dated so a recurring headline is still distinct. */
    public static function title(Draft $draft, Window $window): string
    {
        $title = "{$draft->headline} ({$window->end->modify('-1 second')->format('M j, Y')})";

        return mb_strlen($title) > self::MAX_TITLE ? mb_substr($title, 0, self::MAX_TITLE - 1) . '…' : $title;
    }

    /**
     * The post body in Reddit Markdown: the newsletter without its headline
     * (that is the title), with `owner/repo#123` references linked to GitHub.
     */
    public static function body(Draft $draft, string $footer = ''): string
    {
        $parts = [];
        if ($draft->intro !== '') {
            $parts[] = $draft->intro;
        }
        foreach ($draft->sections as $section) {
            $parts[] = ($section['title'] !== '' ? "## {$section['title']}\n\n" : '') . $section['body'];
        }
        if ($draft->signoff !== '') {
            $parts[] = "*{$draft->signoff}*";
        }
        if ($footer !== '') {
            $parts[] = "---\n\n^({$footer})";
        }
        $text = implode("\n\n", $parts);

        // `code`-wrapped references stay as they are; bare ones become links.
        return (string) preg_replace(
            '/(?<![\w\/`\[])([A-Za-z0-9-]+\/[A-Za-z0-9._-]+)#(\d+)\b(?!`)/',
            '[$1#$2](https://github.com/$1/issues/$2)',
            $text,
        );
    }

    /**
     * Sends a request with a valid access token, fetching one first when needed.
     *
     * @param array<string, scalar>|null $form Form fields for a POST.
     *
     * @return PromiseInterface<array<mixed>>
     */
    private function authorized(string $method, string $url, ?array $form = null): PromiseInterface
    {
        return $this->accessToken()->then(function (string $token) use ($method, $url, $form): PromiseInterface {
            $headers = ['Authorization' => "Bearer {$token}", 'User-Agent' => $this->userAgent()];

            return $form === null
                ? $this->http->get($url, $headers)
                : $this->http->form($url, $form, $headers);
        });
    }

    /** @return PromiseInterface<string> */
    private function accessToken(): PromiseInterface
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return resolve($this->token);
        }

        if ($this->refreshToken) {
            $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $this->refreshToken];
        } elseif ($this->password) {
            $fields = ['grant_type' => 'password', 'username' => $this->username, 'password' => $this->password];
        } else {
            return reject(new \RuntimeException('set REDDIT_REFRESH_TOKEN (or REDDIT_PASSWORD) to let the bot sign in to Reddit'));
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
            'User-Agent' => $this->userAgent(),
        ];

        return $this->http->form(self::AUTH, $fields, $headers)->then(function (array $body): string {
            if (empty($body['access_token'])) {
                $reason = (string) ($body['error'] ?? 'no access token in the reply');

                throw new \RuntimeException("Reddit refused to sign in ({$reason}): check REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET and the credentials");
            }
            $this->token = (string) $body['access_token'];
            $this->tokenExpires = time() + (int) ($body['expires_in'] ?? 3600);
            $this->logger->debug('Signed in to Reddit');

            return $this->token;
        }, static function (\Throwable $e): never {
            throw new \RuntimeException("Could not sign in to Reddit: {$e->getMessage()}", (int) $e->getCode(), $e);
        });
    }

    /** Reddit asks for `platform:app id:version (by /u/username)`. */
    private function userAgent(): string
    {
        return "php:DiscordPHP-Newsletter:1.0 (by /u/{$this->username})";
    }

    /** @param array<mixed> $response */
    private static function throwErrors(array $response): void
    {
        $errors = (array) ($response['json']['errors'] ?? []);
        if ($errors !== []) {
            // Each error is [code, message, field].
            $first = (array) $errors[0];

            throw new \RuntimeException('Reddit said: ' . ($first[1] ?? $first[0] ?? 'an unknown error') . (isset($first[0]) ? " ({$first[0]})" : ''));
        }
    }
}
