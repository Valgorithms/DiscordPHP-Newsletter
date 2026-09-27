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

namespace Newsletter\Http;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * Tiny async JSON client for the third-party APIs the newsletter reads
 * (GitHub, Steam) and writes (GitHub contents, for the websites), driven by
 * the bot's ReactPHP loop.
 *
 * The transport is injectable, exactly like {@see \Newsletter\Llm\OllamaClient},
 * so the sources can be unit-tested with canned responses and no network.
 *
 * @since 1.0.0
 */
final class JsonClient
{
    /** @var callable(string, string, array<string,string>, string): PromiseInterface<array{0: int, 1: string}> */
    private $transport;

    /**
     * @param callable|null      $transport `fn(string $method, string $url, array $headers, string $body): PromiseInterface<array{0: int, 1: string}>`
     *                                      resolving with `[status, body]`. Defaults to a {@see Browser}.
     * @param float              $timeout   Per-request timeout in seconds.
     * @param LoopInterface|null $loop      Only used to build the default transport.
     */
    public function __construct(?callable $transport = null, float $timeout = 30.0, ?LoopInterface $loop = null)
    {
        $this->transport = $transport ?? self::browserTransport($timeout, $loop);
    }

    /**
     * GETs `$url` and resolves with the decoded JSON body.
     *
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<array<mixed>>
     */
    public function get(string $url, array $headers = []): PromiseInterface
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * Sends a request (with `$payload` JSON-encoded as the body, when given)
     * and resolves with the decoded JSON response.
     *
     * Rejects with a {@see \RuntimeException} on a non-2xx status (the
     * exception's code is the HTTP status) or a body that is not JSON.
     *
     * @param array<string, string> $headers
     * @param array<mixed>|null     $payload
     *
     * @return PromiseInterface<array<mixed>>
     */
    public function request(string $method, string $url, array $headers = [], ?array $payload = null): PromiseInterface
    {
        $body = '';
        if ($payload !== null) {
            $headers += ['Content-Type' => 'application/json'];
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $this->send($method, $url, $headers, $body);
    }

    /**
     * POSTs `$fields` form-encoded (`application/x-www-form-urlencoded`, as
     * Reddit's API expects) and resolves with the decoded JSON response.
     *
     * @param array<string, string> $headers
     * @param array<string, scalar> $fields
     *
     * @return PromiseInterface<array<mixed>>
     */
    public function form(string $url, array $fields, array $headers = []): PromiseInterface
    {
        return $this->send('POST', $url, $headers + ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<array<mixed>>
     */
    private function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'DiscordPHP-Newsletter'];

        return ($this->transport)($method, $url, $headers, $body)->then(static function (array $response) use ($url): array {
            [$status, $body] = $response;
            $decoded = json_decode($body, true);

            if ($status < 200 || $status >= 300) {
                // GitHub and Steam say `message`, OAuth says `error`, Telegram says `description`.
                $message = is_array($decoded) && is_string($decoded['message'] ?? $decoded['description'] ?? $decoded['error'] ?? null)
                    ? (string) ($decoded['message'] ?? $decoded['description'] ?? $decoded['error'])
                    : substr($body, 0, 200);

                throw new \RuntimeException("HTTP {$status} from " . self::redact($url) . ": {$message}", $status);
            }
            if (! is_array($decoded)) {
                throw new \RuntimeException('Non-JSON response from ' . self::redact($url));
            }

            return $decoded;
        });
    }

    /** Strips API keys from a URL before it lands in a log or an error message. */
    public static function redact(string $url): string
    {
        return (string) preg_replace('/([?&](?:key|access_token|token)=)[^&]*/i', '$1***', $url);
    }

    /**
     * @return callable(string, string, array<string,string>, string): PromiseInterface<array{0: int, 1: string}>
     */
    private static function browserTransport(float $timeout, ?LoopInterface $loop): callable
    {
        // Keep error responses so the API's error body is readable.
        $browser = (new Browser($loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false);

        return static fn(string $method, string $url, array $headers, string $body): PromiseInterface
            => $browser->request($method, $url, $headers, $body)
                ->then(static fn(ResponseInterface $response): array => [$response->getStatusCode(), (string) $response->getBody()]);
    }
}
