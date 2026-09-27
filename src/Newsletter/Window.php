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

/**
 * The half-open time range `[start, end)` one newsletter edition covers.
 *
 * Both ends carry the owner's time zone, so {@see key()} and {@see label()}
 * name the local day the edition is about.
 *
 * @since 1.0.0
 */
final class Window
{
    public function __construct(
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new \InvalidArgumentException('A window must end after it starts.');
        }
    }

    /**
     * The window that follows the previous edition: from `$last` (or local
     * midnight when there was none) up to `$now`, never longer than `$maxHours`.
     */
    public static function since(?\DateTimeImmutable $last, \DateTimeImmutable $now, int $maxHours = 48): self
    {
        $start = $last ?? $now->setTime(0, 0);
        $floor = $now->modify("-{$maxHours} hours");
        if ($start < $floor) {
            $start = $floor;
        }
        if ($start >= $now) {
            $start = $now->setTime(0, 0);
        }

        return new self($start->setTimezone($now->getTimezone()), $now);
    }

    /** Whether `$time` falls inside the window. */
    public function contains(\DateTimeInterface $time): bool
    {
        return $time >= $this->start && $time < $this->end;
    }

    /** `Y-m-d` of the local day the window ends on (the edition's identity). */
    public function key(): string
    {
        return $this->end->modify('-1 second')->format('Y-m-d');
    }

    /** Human-readable day name, e.g. `Sunday, September 27, 2026`. */
    public function label(): string
    {
        return $this->end->modify('-1 second')->format('l, F j, Y');
    }

    /** Short range for prompts and footers, e.g. `Sep 27 00:00 → Sep 27 18:00 (America/New_York)`. */
    public function range(): string
    {
        return $this->start->format('M j H:i') . ' → ' . $this->end->format('M j H:i') . ' (' . $this->end->getTimezone()->getName() . ')';
    }

    /** @return array{start: string, end: string} */
    public function toArray(): array
    {
        return ['start' => $this->start->format(DATE_ATOM), 'end' => $this->end->format(DATE_ATOM)];
    }

    /** @param array{start: string, end: string} $data */
    public static function fromArray(array $data, ?\DateTimeZone $tz = null): self
    {
        $start = new \DateTimeImmutable($data['start']);
        $end = new \DateTimeImmutable($data['end']);
        if ($tz) {
            $start = $start->setTimezone($tz);
            $end = $end->setTimezone($tz);
        }

        return new self($start, $end);
    }
}
