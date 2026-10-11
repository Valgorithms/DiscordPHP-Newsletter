<?php

declare(strict_types=1);

namespace Newsletter\Sources;

use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/** Bounded, read-only local JSON snapshots; no directory discovery or credential loading. */
final class LocalContextFile
{
    public static function read(string $path): PromiseInterface
    {
        try {
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 2_000_000) {
                throw new \RuntimeException('Configured context file is missing, unreadable, or too large.');
            }
            $raw = file_get_contents($path, false, null, 0, 2_000_001);
            if ($raw === false || strlen($raw) > 2_000_000) {
                throw new \RuntimeException('Configured context file could not be read within its size limit.');
            }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Configured context file must contain a JSON object.');
            }

            return resolve($data);
        } catch (\Throwable) {
            return reject(new \RuntimeException('Configured local context snapshot is unavailable or invalid.'));
        }
    }
}
