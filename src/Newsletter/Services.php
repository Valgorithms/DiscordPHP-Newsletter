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
use React\Promise\PromiseInterface;

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

    /**
     * Checks that Ollama answers and has the configured model, logging what to
     * fix if not. Resolves with null when ready, or the problem as a sentence.
     *
     * @return PromiseInterface<?string>
     */
    public static function checkOllama(OllamaClient $ollama, LoggerInterface $logger): PromiseInterface
    {
        return $ollama->models()->then(
            static function (array $installed) use ($ollama, $logger): ?string {
                if ($ollama->hasModel($installed)) {
                    $logger->info("Ollama is ready: {$ollama->describe()}");

                    return null;
                }
                $problem = "Ollama is running, but the model in OLLAMA_MODEL is not installed ({$ollama->describe()}). "
                    . 'Installed: ' . ($installed ? implode(', ', $installed) : 'none') . '. Run `ollama pull <model>` or fix OLLAMA_MODEL.';
                $logger->error($problem);

                return $problem;
            },
            static function (\Throwable $e) use ($logger): string {
                $logger->error("{$e->getMessage()} Until it is, drafts will be the raw activity list.");

                return $e->getMessage();
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
            Env::int('NEWSLETTER_WORDS', 300),
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

    /**
     * The website publisher, when PUBLISH_TARGETS names any sites.
     *
     * Each site's owner needs a token that can write its repository's contents:
     * `PUBLISH_GITHUB_TOKEN_<OWNER>` (owner upper-cased, non-alphanumerics as `_`,
     * e.g. `PUBLISH_GITHUB_TOKEN_DISCORD_PHP`) wins over `PUBLISH_GITHUB_TOKEN`,
     * which wins over `GITHUB_TOKEN`. A fine-grained token covers one owner, so
     * two owners usually need two; a classic `repo`-scope token covers both.
     */
    public static function sitePublisher(LoopInterface $loop, LoggerInterface $logger): ?SitePublisher
    {
        $targets = SitePublisher::parseTargets(Env::string('PUBLISH_TARGETS') ?? '');
        if ($targets === []) {
            return null;
        }
        $tokenFor = static fn(string $owner): ?string => Env::string('PUBLISH_GITHUB_TOKEN_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $owner)))
            ?? Env::string('PUBLISH_GITHUB_TOKEN')
            ?? Env::string('GITHUB_TOKEN');
        $publisher = new SitePublisher(new JsonClient(null, 30.0, $loop), $targets, $tokenFor, $logger);

        $sites = implode(', ', array_column($targets, 'repo'));
        $publisher->check()->then(static function (array $problems) use ($logger, $sites): void {
            if ($problems === []) {
                $logger->info("Website publishing is ready: {$sites}");
            }
            foreach ($problems as $problem) {
                $logger->warning("Website publishing will fail for {$problem}");
            }
        });

        return $publisher;
    }

    /**
     * The Reddit publisher, when REDDIT_TARGETS names any subreddits or
     * profiles. Logs at startup whether it can sign in and post to each.
     */
    public static function redditPublisher(LoopInterface $loop, LoggerInterface $logger): ?RedditPublisher
    {
        $targets = self::idList('REDDIT_TARGETS') ?: self::idList('REDDIT_SUBREDDIT');
        if ($targets === []) {
            return null;
        }
        $missing = array_filter(['REDDIT_CLIENT_ID', 'REDDIT_CLIENT_SECRET', 'REDDIT_USERNAME'], static fn(string $k) => Env::string($k) === null);
        if ($missing) {
            $logger->warning('Reddit publishing is off until these are set: ' . implode(', ', $missing));

            return null;
        }

        $reddit = new RedditPublisher(
            new JsonClient(null, 30.0, $loop),
            $targets,
            (string) Env::string('REDDIT_CLIENT_ID'),
            (string) Env::string('REDDIT_CLIENT_SECRET'),
            (string) Env::string('REDDIT_USERNAME'),
            Env::string('REDDIT_REFRESH_TOKEN'),
            Env::string('REDDIT_PASSWORD'),
            Env::string('REDDIT_FLAIR_ID'),
            $logger,
        );
        $reddit->check()->then(static function (array $problems) use ($logger, $reddit): void {
            if ($problems === []) {
                $logger->info('Reddit publishing is ready: ' . implode(', ', $reddit->describe()));
            }
            foreach ($problems as $problem) {
                $logger->warning("Reddit publishing will fail for {$problem}");
            }
        });

        return $reddit;
    }

    /** @return list<string> */
    public static function idList(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Env::string($key) ?? ''))));
    }
}
