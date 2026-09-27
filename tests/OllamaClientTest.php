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

use Newsletter\Llm\OllamaClient;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class OllamaClientTest extends TestCase
{
    private static function client(callable $transport, string $model = 'gemma3:27b', string $url = 'http://localhost:11434'): OllamaClient
    {
        return new OllamaClient($url, $model, $transport);
    }

    public function testListsInstalledModelsAndMatchesTheConfiguredOne(): void
    {
        $asked = null;
        $client = self::client(static function (string $method, string $url) use (&$asked): PromiseInterface {
            $asked = "{$method} {$url}";

            return resolve(json_encode(['models' => [['name' => 'gemma3:27b'], ['name' => 'llama3:latest']]]));
        });

        $installed = self::settle($client->models());

        $this->assertSame('GET http://localhost:11434/api/tags', $asked);
        $this->assertTrue($client->hasModel($installed));
        $this->assertTrue(self::client(static fn() => resolve(''), 'llama3')->hasModel($installed), 'a tag-less name means :latest');
        $this->assertFalse(self::client(static fn() => resolve(''), 'qwen3:8b')->hasModel($installed));
    }

    public function testOpenAiCompatibleServersListModelsUnderV1(): void
    {
        $asked = null;
        $client = self::client(static function (string $method, string $url) use (&$asked): PromiseInterface {
            $asked = $url;

            return resolve(json_encode(['data' => [['id' => 'gemma3:27b']]]));
        }, url: 'http://gpu-box:11434/v1');

        $this->assertSame(['gemma3:27b'], self::settle($client->models()));
        $this->assertSame('http://gpu-box:11434/v1/models', $asked);
    }

    public function testARefusedConnectionSaysWhatToCheck(): void
    {
        $refused = static fn() => reject(new \RuntimeException('Connection to tcp://localhost:11434 failed: Last error for IPv4: … actively refused it (ECONNREFUSED)'));

        try {
            self::settle(self::client($refused)->chat([['role' => 'user', 'content' => 'hi']]));
            $this->fail('expected a rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not reach Ollama at http://localhost:11434: nothing is listening there', $e->getMessage());
            $this->assertStringContainsString('OLLAMA_URL', $e->getMessage());
        }
    }

    public function testAThinkingModelThatOnlyReasonedIsExplained(): void
    {
        $client = self::client(static fn() => resolve(json_encode(['message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'Let me think about the newsletter…']])));

        $this->expectExceptionMessage('set OLLAMA_THINK=0');
        self::settle($client->chat([['role' => 'user', 'content' => 'hi']], null));
    }
}
