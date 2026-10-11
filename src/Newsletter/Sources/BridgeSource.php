<?php

declare(strict_types=1);

namespace Newsletter\Sources;

use Newsletter\Window;
use React\Promise\PromiseInterface;

/** Reads the explicitly exported owner-only Bridge feed, never webhook display names. */
final class BridgeSource implements Source
{
    private readonly \Closure $read;

    /** @param callable(): PromiseInterface<array> $read Read-only snapshot adapter. */
    public function __construct(callable $read)
    {
        $this->read = \Closure::fromCallable($read);
    }

    public function name(): string
    {
        return 'bridge';
    }

    public function collect(Window $window): PromiseInterface
    {
        return ($this->read)()->then(function (array $snapshot) use ($window): SourceReport {
            if (($snapshot['version'] ?? null) !== 1 || ! is_array($snapshot['messages'] ?? null)) {
                throw new \RuntimeException('Unsupported Bridge snapshot.');
            }
            $errors = [];
            $start = self::date($snapshot['coverage_start'] ?? null);
            $last = self::date($snapshot['updated_at'] ?? null);
            if ($start === null || $last === null || $start > $window->start || $last < $window->end->modify('-10 minutes')) {
                $errors[] = 'Bridge recording covers only part of this window; it may have restarted, fallen behind, or evicted old messages.';
            }
            $lines = [];
            $seen = [];
            foreach ($snapshot['messages'] as $message) {
                if (! is_array($message) || ! is_string($message['text'] ?? null) || ! is_string($message['platform'] ?? null)
                    || ! is_string($message['room'] ?? null) || ! is_string($message['id'] ?? null)
                    || ! is_string($message['author_id'] ?? null) || $message['author_id'] === '') {
                    $errors[] = 'A malformed Bridge entry was omitted.';

                    continue;
                }
                $at = self::date($message['updated_at'] ?? null);
                $created = self::date($message['created_at'] ?? null);
                if ($at === null || $created === null || $created > $at) {
                    $errors[] = 'An undated or inconsistent Bridge entry was omitted.';

                    continue;
                }
                if (! $window->contains($at)) {
                    continue;
                }
                $key = json_encode([$message['platform'], $message['room'], $message['id']]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $action = ($message['revised'] ?? false) ? 'revised an earlier message' : 'said';
                $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message['text'])), 0, 600);
                $platform = mb_substr($message['platform'], 0, 40);
                $room = mb_substr($message['room'], 0, 100);
                $lines[] = '[' . $at->format(DATE_ATOM) . "] [{$platform} / {$room}] owner {$action}: " . json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . ' (self-reported conversation; not independent proof of completion, release or deployment)';
            }
            if (count($lines) > 60) {
                $errors[] = 'Additional owner messages were omitted from this bounded report.';
            }

            return new SourceReport('bridge', 'Public Bridge conversations', array_slice($lines, -60), [], array_values(array_unique($errors)));
        })->then(null, static fn(\Throwable $e): SourceReport => SourceReport::failed('bridge', 'Public Bridge conversations', 'The local Bridge feed is unavailable or invalid.'));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
