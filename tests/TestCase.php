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

use Newsletter\Http\JsonClient;
use Newsletter\Llm\OllamaClient;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<string> Prompts the fake LLM received, in order. */
    protected array $prompts = [];

    /**
     * Settles a promise that the fakes resolve synchronously.
     */
    protected static function settle(PromiseInterface $promise): mixed
    {
        $done = false;
        $value = null;
        $error = null;
        $promise->then(function ($v) use (&$done, &$value) {
            $done = true;
            $value = $v;
        }, function ($e) use (&$done, &$error) {
            $done = true;
            $error = $e;
        });
        self::assertTrue($done, 'The promise did not settle synchronously.');
        if ($error) {
            throw $error;
        }

        return $value;
    }

    /**
     * An Ollama client whose replies come from `$replies` in order. A Throwable
     * entry makes that call fail.
     *
     * @param list<string|\Throwable> $replies
     */
    protected function fakeLlm(array $replies): OllamaClient
    {
        return new OllamaClient('http://ollama:11434', 'test-model', function (string $method, string $url, array $headers, string $body) use (&$replies): PromiseInterface {
            $sent = json_decode($body, true);
            $this->prompts[] = (string) end($sent['messages'])['content'];
            $reply = array_shift($replies) ?? new \RuntimeException('No more fake replies');
            if ($reply instanceof \Throwable) {
                return reject($reply);
            }

            return resolve(json_encode(['message' => ['role' => 'assistant', 'content' => $reply]]));
        });
    }

    /**
     * A JSON client answering by URL substring. A route key may start with a
     * method (`PUT /contents`) to match only that method.
     *
     * @param array<string, array<mixed>|array{0: int, 1: string}|\Closure> $routes   substring => decoded body, [status, raw body],
     *                                                                                or fn(string $body) returning either
     * @param list<string>                                                  $log      Every requested URL (non-GET ones as "METHOD url").
     * @param list<array<mixed>>                                            $payloads Every decoded request body.
     */
    protected static function fakeHttp(array $routes, ?array &$log = null, ?array &$payloads = null): JsonClient
    {
        $log = [];
        $payloads = [];

        return new JsonClient(static function (string $method, string $url, array $headers, string $body) use ($routes, &$log, &$payloads): PromiseInterface {
            $log[] = $method === 'GET' ? $url : "{$method} {$url}";
            if ($body !== '') {
                // JSON bodies decode as JSON; form-encoded ones (Reddit) as fields.
                $decoded = json_decode($body, true);
                if (! is_array($decoded)) {
                    parse_str($body, $decoded);
                }
                $payloads[] = $decoded + ['__headers' => $headers];
            }
            foreach ($routes as $needle => $response) {
                if (preg_match('/^(GET|PUT|POST|PATCH|DELETE) (.*)$/', (string) $needle, $m)) {
                    if ($m[1] !== $method) {
                        continue;
                    }
                    $needle = $m[2];
                }
                if (str_contains($url, (string) $needle)) {
                    if ($response instanceof \Closure) {
                        $response = $response($body);
                    }

                    return resolve(isset($response[0], $response[1]) && is_int($response[0]) && is_string($response[1]) ? $response : [200, json_encode($response)]);
                }
            }

            return resolve([404, '{"message":"Not Found"}']);
        });
    }

    protected static function tempFile(string $name): string
    {
        $dir = sys_get_temp_dir() . '/newsletter-tests-' . getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir . '/' . $name . '-' . bin2hex(random_bytes(4));
        @unlink($path);

        return $path;
    }
}
