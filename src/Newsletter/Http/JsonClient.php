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
 * Tiny async `GET → decoded JSON` client for the third-party APIs the
 * newsletter reads (GitHub, Steam), driven by the bot's ReactPHP loop.
 *
 * The transport is injectable, exactly like {@see \Newsletter\Llm\OllamaClient},
 * so the sources can be unit-tested with canned responses and no network.
 *
 * @since 1.0.0
 */
final class JsonClient
{
    /** @var callable(string, array<string,string>): PromiseInterface<array{0: int, 1: string}> */
    private $transport;

    /**
     * @param callable|null      $transport `fn(string $url, array $headers): PromiseInterface<array{0: int, 1: string}>`
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
     * Rejects with a {@see \RuntimeException} on a non-2xx status or a body
     * that is not a JSON object/array.
     *
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<array<mixed>>
     */
    public function get(string $url, array $headers = []): PromiseInterface
    {
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'DiscordPHP-Newsletter'];

        return ($this->transport)($url, $headers)->then(static function (array $response) use ($url): array {
            [$status, $body] = $response;
            $decoded = json_decode($body, true);

            if ($status < 200 || $status >= 300) {
                $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : substr($body, 0, 200);

                throw new \RuntimeException("HTTP {$status} from " . self::redact($url) . ": {$message}");
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
     * @return callable(string, array<string,string>): PromiseInterface<array{0: int, 1: string}>
     */
    private static function browserTransport(float $timeout, ?LoopInterface $loop): callable
    {
        // Keep error responses so the API's error body is readable.
        $browser = (new Browser($loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false);

        return static fn(string $url, array $headers): PromiseInterface
            => $browser->get($url, $headers)
                ->then(static fn(ResponseInterface $response): array => [$response->getStatusCode(), (string) $response->getBody()]);
    }
}
