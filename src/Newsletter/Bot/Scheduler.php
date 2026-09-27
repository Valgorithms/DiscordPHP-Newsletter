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

namespace Newsletter\Bot;

use Newsletter\StateStore;
use Newsletter\Window;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Fires the daily edition at the configured local time, on the bot's event
 * loop (no cron needed), and catches up once on startup when the bot was down
 * at the scheduled time.
 *
 * Each scheduled window starts where the previous one ended, so nothing falls
 * between two editions even if a run is late.
 *
 * @since 1.0.0
 */
final class Scheduler
{
    /** @var callable(Window): PromiseInterface */
    private $generate;

    /**
     * @param string                             $time     Local `HH:MM` the edition is built at.
     * @param callable(Window): PromiseInterface $generate Builds and sends an edition for a window.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly StateStore $state,
        callable $generate,
        private readonly string $time,
        private readonly \DateTimeZone $tz,
        private readonly LoggerInterface $logger,
        private readonly bool $catchUp = true,
    ) {
        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new \InvalidArgumentException("NEWSLETTER_TIME must be HH:MM, got \"{$time}\".");
        }
        $this->generate = $generate;
    }

    /** The first scheduled run strictly after `$now`. */
    public static function nextRun(\DateTimeImmutable $now, string $time, \DateTimeZone $tz): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $local = $now->setTimezone($tz);
        $run = $local->setTime($h, $m);

        return $run > $local ? $run : $local->modify('+1 day')->setTime($h, $m);
    }

    /** The most recent scheduled run at or before `$now`. */
    public static function previousRun(\DateTimeImmutable $now, string $time, \DateTimeZone $tz): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return self::nextRun($now, $time, $tz)->modify('-1 day')->setTime($h, $m);
    }

    public function start(): void
    {
        $now = new \DateTimeImmutable('now', $this->tz);
        $last = $this->state->lastWindowEnd();
        $missed = self::previousRun($now, $this->time, $this->tz);

        if ($this->catchUp && $last !== null && $last < $missed->modify('-1 minute')) {
            $this->logger->info('Missed the ' . $missed->format('Y-m-d H:i') . ' edition while offline; catching up');
            $this->fire(Window::since($last->setTimezone($this->tz), $missed));
        } elseif ($last === null) {
            // First run ever: the first edition covers from today's local midnight.
            $this->state->setLastWindowEnd($now->setTime(0, 0));
        }

        $this->arm();
    }

    private function arm(): void
    {
        $now = new \DateTimeImmutable('now', $this->tz);
        $next = self::nextRun($now, $this->time, $this->tz);
        $this->logger->info('Next newsletter at ' . $next->format('Y-m-d H:i T'));

        $this->loop->addTimer(max(1, $next->getTimestamp() - $now->getTimestamp()), function () use ($next): void {
            $this->fire(Window::since($this->state->lastWindowEnd()?->setTimezone($this->tz), $next));
            $this->arm();
        });
    }

    private function fire(Window $window): void
    {
        // Claim the window up front so a slow model or a crash cannot double-cover it.
        $this->state->setLastWindowEnd($window->end);
        ($this->generate)($window)->then(null, function (\Throwable $e) use ($window): void {
            $this->logger->error("Newsletter for {$window->key()} failed: {$e->getMessage()}");
        });
    }
}
