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
use Newsletter\Llm\OllamaClient;
use Newsletter\Sources\GitHubSource;
use Newsletter\Sources\SteamSnapshots;
use Newsletter\Sources\SteamSource;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

/**
 * Builds the environment-configured services shared by `bot.php` and
 * `preview.php`, so both entry points read `.env` the same way.
 *
 * @since 1.0.0
 */
final class Services
{
    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(Env::string('NEWSLETTER_TIMEZONE') ?? date_default_timezone_get());
    }

    /** @throws \RuntimeException When OLLAMA_URL is not set. */
    public static function ollama(LoopInterface $loop): OllamaClient
    {
        $url = Env::string('OLLAMA_URL') ?? throw new \RuntimeException('OLLAMA_URL is not set; the newsletter is written by your local Ollama model.');

        return new OllamaClient(
            $url,
            Env::string('OLLAMA_MODEL') ?? 'gemma3:27b',
            null,
            Env::float('OLLAMA_TIMEOUT', 300),
            Env::int('OLLAMA_NUM_CTX', 32768),
            $loop,
            match (getenv('OLLAMA_THINK')) {
                '1', 'true' => true, '0', 'false' => false, default => null
            },
        );
    }

    public static function writer(OllamaClient $ollama, LoggerInterface $logger): Writer
    {
        return new Writer(
            $ollama,
            Env::string('NEWSLETTER_AUTHOR') ?? 'Valithor',
            Env::string('NEWSLETTER_VOICE') ?? 'first',
            Env::string('NEWSLETTER_STYLE') ?? '',
            Env::int('NEWSLETTER_WORDS', 350),
            Env::flag('NEWSLETTER_FACT_CHECK'),
            $logger,
        );
    }

    /**
     * The GitHub and Steam sources that are configured (Discord is wired by the bot).
     *
     * @return list<Sources\Source>
     */
    public static function webSources(LoopInterface $loop, StateStore $state, LoggerInterface $logger): array
    {
        $http = new JsonClient(null, 30.0, $loop);
        $sources = [];

        if ($user = Env::string('GITHUB_USERNAME')) {
            $sources[] = new GitHubSource($http, $user, Env::string('GITHUB_TOKEN'), Env::flag('GITHUB_INCLUDE_PRIVATE', false));
        } else {
            $logger->notice('GITHUB_USERNAME is not set; GitHub activity is skipped.');
        }

        if (($key = Env::string('STEAM_API_KEY')) && ($id = Env::string('STEAM_ID'))) {
            $sources[] = new SteamSource($http, $key, $id, new SteamSnapshots($state));
        } else {
            $logger->notice('STEAM_API_KEY / STEAM_ID are not set; Steam activity is skipped.');
        }

        return $sources;
    }

    /** @return list<string> */
    public static function idList(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Env::string($key) ?? ''))));
    }
}
