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
 * One-time helper: gets a permanent Reddit refresh token for REDDIT_REFRESH_TOKEN,
 * which (unlike a password) also works with two-factor authentication.
 *
 *   1. On https://www.reddit.com/prefs/apps, give your "script" app the redirect
 *      uri http://localhost:65010/reddit-token (any address works; nothing needs
 *      to listen there).
 *   2. Put REDDIT_CLIENT_ID and REDDIT_CLIENT_SECRET in .env.
 *   3. php reddit-token.php, open the link it prints, allow access, then paste the
 *      address your browser ends up on (the page itself will fail to load; that
 *      is expected).
 */

use Newsletter\Env;

require __DIR__ . '/vendor/autoload.php';

($envPath = Env::locate(__DIR__)) ? Env::load($envPath) : throw new RuntimeException('No .env found. Run: cp env.example .env');

$clientId = Env::string('REDDIT_CLIENT_ID') ?? exit("Set REDDIT_CLIENT_ID and REDDIT_CLIENT_SECRET in .env first.\n");
$secret = Env::string('REDDIT_CLIENT_SECRET') ?? exit("Set REDDIT_CLIENT_SECRET in .env first.\n");
$redirect = $argv[1] ?? 'http://localhost:65010/reddit-token';
$state = bin2hex(random_bytes(8));

echo "Open this link, sign in as the account that should post, and press Allow:\n\n  https://www.reddit.com/api/v1/authorize?" . http_build_query([
    'client_id' => $clientId,
    'response_type' => 'code',
    'state' => $state,
    'redirect_uri' => $redirect,
    'duration' => 'permanent',
    'scope' => 'submit edit read identity',
]) . "\n\n(The redirect uri must match your app's exactly: {$redirect}. Pass another as the first argument if yours differs.)\n\n";

$pasted = trim((string) readline('Paste the address your browser ended up on: '));
parse_str((string) parse_url($pasted, PHP_URL_QUERY), $query);
if (($query['state'] ?? null) !== $state) {
    exit('That address is not from this link (' . ($query['error'] ?? 'the state does not match') . "). Run it again.\n");
}
if (empty($query['code'])) {
    exit('Reddit did not grant access: ' . ($query['error'] ?? 'no code in the address') . "\n");
}

$response = @file_get_contents('https://www.reddit.com/api/v1/access_token', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => implode("\r\n", [
        'Authorization: Basic ' . base64_encode("{$clientId}:{$secret}"),
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: php:DiscordPHP-Newsletter:1.0 (token helper)',
    ]),
    'content' => http_build_query(['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => $redirect]),
    'ignore_errors' => true,
]]));
$token = json_decode((string) $response, true);

if (! is_array($token) || empty($token['refresh_token'])) {
    exit('Reddit did not return a refresh token: ' . ($token['error'] ?? substr((string) $response, 0, 200)) . "\n");
}

echo "\nAdd this line to .env (and keep it secret, like a password):\n\nREDDIT_REFRESH_TOKEN={$token['refresh_token']}\n";
