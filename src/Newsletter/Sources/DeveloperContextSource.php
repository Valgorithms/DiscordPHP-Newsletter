<?php

declare(strict_types=1);

namespace Newsletter\Sources;

use Newsletter\Window;
use React\Promise\PromiseInterface;

/** Operator-curated public project facts, explicitly separate from current activity. */
final class DeveloperContextSource implements Source
{
    private readonly \Closure $read;

    public function __construct(callable $read)
    {
        $this->read = \Closure::fromCallable($read);
    }

    public function name(): string
    {
        return 'developer_context';
    }

    public function collect(Window $window): PromiseInterface
    {
        return ($this->read)()->then(static function (array $snapshot): SourceReport {
            if (($snapshot['version'] ?? null) !== 1 || ! is_array($snapshot['facts'] ?? null)) {
                throw new \RuntimeException('Unsupported developer context.');
            }
            $lines = [];
            $errors = [];
            foreach (array_slice($snapshot['facts'], 0, 40) as $fact) {
                if (! is_array($fact) || ! is_string($fact['statement'] ?? null) || ! is_string($fact['source_url'] ?? null)
                    || ! filter_var($fact['source_url'], FILTER_VALIDATE_URL)
                    || ! in_array(parse_url($fact['source_url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                    $errors[] = 'A background fact without a public source URL was omitted.';

                    continue;
                }
                $statement = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $fact['statement'])), 0, 700);
                $lines[] = json_encode($statement, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' — source: ' . mb_substr($fact['source_url'], 0, 500);
            }

            return new SourceReport('developer_context', 'Developer and project background', $lines, [], $errors, true);
        })->then(null, static fn(\Throwable $e): SourceReport => new SourceReport('developer_context', 'Developer and project background', [], [], ['Configured background is unavailable or invalid.'], true));
    }
}
