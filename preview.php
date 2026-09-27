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

/*
 * Dry run: collect today's activity (GitHub, Steam, and whatever the running
 * bot has logged from Discord), have the local model write the newsletter, and
 * print it. Nothing is sent to Discord and no edition is stored.
 *
 *   php preview.php            # today so far
 *   php preview.php --facts    # also print the raw facts and the model's notes
 */

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Newsletter\Env;
use Newsletter\Services;
use Newsletter\Sources\DiscordActivityLog;
use Newsletter\Sources\DiscordSource;
use Newsletter\Sources\SourceReport;
use Newsletter\StateStore;
use Newsletter\Window;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

require __DIR__ . '/vendor/autoload.php';

($envPath = Env::locate(__DIR__)) ? Env::load($envPath) : throw new RuntimeException('No .env found. Run: cp env.example .env');

$showFacts = in_array('--facts', $argv, true);
$logger = new Logger('Preview', [new StreamHandler('php://stderr', Level::Info)]);
$loop = Loop::get();
$state = new StateStore(__DIR__ . '/var/state.json');

$sources = Services::webSources($loop, $state, $logger);
$sources[] = new DiscordSource(new DiscordActivityLog(__DIR__ . '/var/discord-activity.jsonl'));

$window = Window::since(null, new DateTimeImmutable('now', Services::timezone()));
$ollama = Services::ollama($loop);
$writer = Services::writer($ollama, $logger);
$pipeline = new Newsletter\Pipeline($sources, $writer, $state, $logger);

// Without the model a preview only shows the template, so say why and stop.
Services::checkOllama($ollama, $logger)
    ->then(function (?string $problem) use ($pipeline, $window): PromiseInterface {
        if ($problem !== null) {
            fwrite(STDERR, "\nCannot preview: {$problem}\n");
            exit(1);
        }

        return $pipeline->collect($window);
    })
    ->then(function (array $reports) use ($writer, $window, $showFacts) {
        if ($showFacts) {
            echo implode("\n\n", array_map(static fn(SourceReport $r) => $r->toPromptText(), $reports)), "\n\n";
        }

        return $writer->write($window, $reports);
    })
    ->then(function (array $written) use ($showFacts): void {
        if ($showFacts) {
            foreach ($written['notes'] as $source => $notes) {
                echo "--- notes: {$source} ---\n{$notes}\n\n";
            }
        }
        if ($written['fallback'] !== null) {
            fwrite(STDERR, "\nThe model could not write the newsletter, so this is the template instead.\nReason: {$written['fallback']}\n\n");
        }
        echo "===================== DRAFT =====================\n\n", $written['draft']->toMarkdown(), "\n";
    }, function (Throwable $e): void {
        fwrite(STDERR, "Preview failed: {$e->getMessage()}\n");
        exit(1);
    });

$loop->run();
