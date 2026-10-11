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

use Newsletter\Llm\OllamaClient;
use Newsletter\Sources\SourceReport;
use Newsletter\Window;
use Newsletter\Writer;
use React\EventLoop\Loop;

require dirname(__DIR__) . '/vendor/autoload.php';

// Opt-in fixture evaluation: never loads .env, bot state, real sources or publishers.
$options = getopt('', ['url:', 'model:', 'output:']);
$url = (string) ($options['url'] ?? '');
$model = (string) ($options['model'] ?? '');
$parts = parse_url($url);
$host = (string) ($parts['host'] ?? '');
$privateIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    && (str_starts_with($host, '10.') || str_starts_with($host, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host));
$local = $privateIp || in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
if ($model === '' || ! $local || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
    fwrite(STDERR, "Usage: php scripts/evaluate-writer.php --url=http://localhost:11434 --model=local-model [--output=sample.json]\nOnly loopback/private IPv4 model endpoints without embedded credentials are allowed.\n");
    exit(2);
}

$reports = [
    new SourceReport('github', 'GitHub activity', [
        '[example/DiscordPHP] merged pull request #12 "Fix invite expiry parsing". No release, deployment or live verification was recorded.',
        '[example/FrontierSystems] opened pull request #42 "Keep inventory focus after closing the crafting panel"; it is still open for review.',
    ]),
    new SourceReport('discord', 'Discord activity', [
        'Shared the merged example/DiscordPHP invite parsing change with the community. This is the same change reported by GitHub.',
    ]),
    SourceReport::failed('steam', 'Steam activity', 'synthetic unavailable transport'),
];
$previous = [[
    'key' => '2026-10-10',
    'date' => 'Saturday, October 10, 2026',
    'markdown' => "# Introducing Frontier Systems\nI introduced Frontier Systems, an early cooperative engineering project.",
]];
$window = new Window(new DateTimeImmutable('2026-10-11T00:00:00-04:00'), new DateTimeImmutable('2026-10-11T18:00:00-04:00'));
$writer = new Writer(new OllamaClient($url, $model, timeout: 90.0, think: false), 'Alex', words: 160);
$exit = 0;
$started = microtime(true);
$writer->write($window, $reports, $previous)->then(
    function (array $written) use ($options, $model, $started, &$exit): void {
        $result = [
            'fixture' => true,
            'model' => $model,
            'elapsed_seconds' => round(microtime(true) - $started, 2),
            'draft' => $written['draft']->toMarkdown(),
            'notes' => $written['notes'],
            'fallback' => $written['fallback'],
            'review_checklist' => [
                'Invite parsing is merged, not released, deployed or proven in a live test.',
                'Inventory focus remains an open PR; Frontier Systems is not introduced again.',
                'The same invite change appears once across GitHub and Discord coverage.',
                'No invented playtime or quiet-day claim; no raw transport diagnostics.',
                'Concrete opening, restrained claims, no branch names or length padding.',
            ],
        ];
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (isset($options['output']) && file_put_contents((string) $options['output'], $json) === false) {
            throw new RuntimeException('Could not save the evaluation output.');
        }
        echo $json;
        $exit = $written['fallback'] === null ? 0 : 1;
    },
    function (Throwable $error) use (&$exit): void {
        fwrite(STDERR, 'Evaluation failed: ' . $error->getMessage() . "\n");
        $exit = 1;
    },
)->then(null, function (Throwable $error) use (&$exit): void {
    fwrite(STDERR, 'Evaluation output failed: ' . $error->getMessage() . "\n");
    $exit = 1;
});
Loop::run();
exit($exit);
