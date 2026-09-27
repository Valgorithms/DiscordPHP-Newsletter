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

use Discord\Discord;
use Discord\Parts\User\Activity;
use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Newsletter\Bot\ActivityRecorder;
use Newsletter\Bot\ApprovalFlow;
use Newsletter\Bot\Scheduler;
use Newsletter\Env;
use Newsletter\Pipeline;
use Newsletter\ReplyInterpreter;
use Newsletter\Services;
use Newsletter\Sources\DiscordActivityLog;
use Newsletter\Sources\DiscordSource;
use Newsletter\Sources\SteamSource;
use Newsletter\StateStore;
use Newsletter\Window;

use function React\Promise\set_rejection_handler;

/*
 * The project directory. Works when run as `php bot.php` from the checkout, and when
 * run as a phpacker binary (which lands in `bin/build/<name>/<platform>/`),
 * launched directly or from a shortcut, from any working directory: it walks up
 * from the executable, then from the working directory, to the first folder that
 * has `vendor/autoload.php` or a `.env`. This runs before the autoloader, so it
 * stays inline.
 */
$baseDir = (static function (): string {
    $seen = [];
    foreach ([\Phar::running(false) ?: null, __FILE__, \getcwd() ?: null] as $start) {
        if ($start === null) {
            continue;
        }
        $dir = \is_dir($start) ? $start : \dirname((string) \preg_replace('#^phar://#', '', $start));
        for ($i = 0; $i < 12 && ! isset($seen[$dir]); $i++) {
            $seen[$dir] = true;
            if (\is_file($dir . '/vendor/autoload.php') || \is_file($dir . '/.env')) {
                return $dir;
            }
            if (($up = \dirname($dir)) === $dir) {
                break;
            }
            $dir = $up;
        }
    }

    return \getcwd() ?: __DIR__;
})();

\is_file($baseDir . '/vendor/autoload.php')
    ? require $baseDir . '/vendor/autoload.php'
    : throw new \RuntimeException("Composer's autoloader was not found. Run `composer install`, and keep the binary inside the project folder (searched up from " . \dirname(\Phar::running(false) ?: __FILE__) . ').');

// --- configuration -------------------------------------------------------------

($envPath = Env::locate($baseDir)) ? Env::load($envPath) : throw new RuntimeException('No .env found. Run: cp env.example .env');

$token = Env::string('TOKEN') ?? throw new RuntimeException('TOKEN (the Discord bot token) is required.');
$ownerId = Env::string('OWNER_ID') ?? throw new RuntimeException('OWNER_ID (your Discord user id) is required.');
$channelId = Env::string('NEWSLETTER_CHANNEL_ID') ?? throw new RuntimeException('NEWSLETTER_CHANNEL_ID is required.');
$tz = Services::timezone();

$streamHandler = new StreamHandler('php://stdout', Level::Info);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('Newsletter', [$streamHandler]);

$intents = Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT;
if (Env::flag('DISCORD_TRACK_MEMBERS', false)) {
    $intents |= Intents::GUILD_MEMBERS; // privileged: enable "Server Members Intent" in the developer portal
}

$discord = new Discord([
    'token' => $token,
    'logger' => $logger,
    'intents' => $intents,
    'loadAllMembers' => false,
    'disableVoiceClient' => true,
]);

set_rejection_handler(static fn(Throwable $e) => $logger->warning("Unhandled rejection: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]"));

// --- services --------------------------------------------------------------------

$state = new StateStore($baseDir . '/var/state.json');
$activityLog = new DiscordActivityLog($baseDir . '/var/discord-activity.jsonl');
$ollama = Services::ollama($discord->getLoop());
$writer = Services::writer($ollama, $logger);
Services::checkOllama($ollama, $logger);

$recorder = new ActivityRecorder(
    $discord,
    $activityLog,
    $ownerId,
    Services::idList('DISCORD_GUILD_IDS'),
    Services::idList('DISCORD_IGNORE_CHANNEL_IDS'),
);

$sources = Services::webSources($discord->getLoop(), $state, $logger);
$sources[] = new DiscordSource(
    $activityLog,
    Env::flag('DISCORD_AUDIT_LOG') ? $recorder->auditLines(...) : null,
    $recorder->flush(...),
);

$pipeline = new Pipeline($sources, $writer, $state, $logger);
$flow = new ApprovalFlow($discord, $state, $pipeline, $writer, new ReplyInterpreter($ollama), $ownerId, $channelId, $tz, $logger, Services::sitePublisher($discord->getLoop(), $logger), Services::redditPublisher($discord->getLoop(), $logger), Env::string('REDDIT_FOOTER') ?? '');

// --- wiring --------------------------------------------------------------------------

$discord->on('init', function (Discord $discord) use ($recorder, $flow, $state, $sources, $tz, $logger): void {
    $logger->info("Logged in as {$discord->username}");
    $discord->updatePresence(new Activity($discord, ['name' => 'your day', 'type' => Activity::TYPE_WATCHING]));

    $recorder->register();
    $flow->register();
    $state->pruneEditions();

    // Steam only reports lifetime playtime; hourly snapshots make daily deltas possible.
    foreach ($sources as $source) {
        if ($source instanceof SteamSource) {
            $snapshot = static fn() => $source->snapshot()->then(null, static fn(Throwable $e) => $logger->notice("Steam snapshot failed: {$e->getMessage()}"));
            $snapshot();
            $discord->getLoop()->addPeriodicTimer(3600, $snapshot);
        }
    }

    (new Scheduler(
        $discord->getLoop(),
        $state,
        static fn(Window $window) => $flow->generate($window),
        Env::string('NEWSLETTER_TIME') ?? '18:00',
        $tz,
        $logger,
        Env::flag('NEWSLETTER_CATCH_UP'),
    ))->start();
});

$discord->run();
