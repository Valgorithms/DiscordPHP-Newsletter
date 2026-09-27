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

namespace Tests;

use Newsletter\Window;

final class WindowTest extends TestCase
{
    public function testSinceStartsAtLocalMidnightWithoutAPreviousEdition(): void
    {
        $now = new \DateTimeImmutable('2026-09-27 23:30', new \DateTimeZone('America/New_York'));
        $window = Window::since(null, $now);

        $this->assertSame('2026-09-27T00:00:00-04:00', $window->start->format(DATE_ATOM));
        $this->assertSame('2026-09-27', $window->key());
        $this->assertSame('Sunday, September 27, 2026', $window->label());
    }

    public function testSinceContinuesFromThePreviousEndButCapsTheLength(): void
    {
        $tz = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2026-09-27 23:30', $tz);

        $this->assertSame('2026-09-26 23:30', Window::since($now->modify('-1 day'), $now)->start->format('Y-m-d H:i'));
        $this->assertSame('2026-09-25 23:30', Window::since($now->modify('-9 days'), $now, 48)->start->format('Y-m-d H:i'));
    }

    public function testContainsIsHalfOpen(): void
    {
        $window = new Window(new \DateTimeImmutable('2026-09-27 00:00Z'), new \DateTimeImmutable('2026-09-28 00:00Z'));

        $this->assertTrue($window->contains(new \DateTimeImmutable('2026-09-27 00:00Z')));
        $this->assertFalse($window->contains(new \DateTimeImmutable('2026-09-28 00:00Z')));
    }

    public function testRoundTripsThroughAnArray(): void
    {
        $window = new Window(new \DateTimeImmutable('2026-09-27 00:00Z'), new \DateTimeImmutable('2026-09-27 23:00Z'));
        $copy = Window::fromArray($window->toArray());

        $this->assertEquals($window->start, $copy->start);
        $this->assertEquals($window->end, $copy->end);
    }
}
