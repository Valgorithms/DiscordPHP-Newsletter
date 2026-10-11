<?php

declare(strict_types=1);

namespace Tests;

use Newsletter\Draft;
use Newsletter\Pipeline;
use Newsletter\Sources\Source;
use Newsletter\Sources\SourceReport;
use Newsletter\StateStore;
use Newsletter\Window;
use Newsletter\Writer;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class PublishedWindowTest extends TestCase
{
    private function window(string $start, string $end): Window
    {
        return new Window(new \DateTimeImmutable($start), new \DateTimeImmutable($end));
    }

    private function edition(string $key, string $end, string $status = StateStore::STATUS_POSTED): array
    {
        return ['key' => $key, 'status' => $status, 'window' => $this->window('2026-10-11T00:00:00-04:00', $end)->toArray(),
            'draft' => (new Draft('Earlier fix', 'I fixed invite parsing.', []))->toArray(), 'posted_at' => '2026-10-11T14:00:00-04:00'];
    }

    public function testCutoffSurvivesPruningRestartAndOutOfOrderPublication(): void
    {
        $path = self::tempFile('published-window');
        $state = new StateStore($path);
        $state->setLastWindowEnd(new \DateTimeImmutable('2026-10-11T00:00:00-04:00'));
        $early = $this->edition('early', '2026-10-11T13:00:00-04:00');
        $state->putEdition($early);
        $state->recordPublished($early);
        $state->recordPublished($this->edition('older', '2026-10-11T12:00:00-04:00'));
        $state->pruneEditions(0);
        $state = new StateStore($path);
        $next = $state->unpublishedWindow($this->window('2026-10-11T00:00:00-04:00', '2026-10-11T18:00:00-04:00'));
        self::assertSame('13:00', $next->start->format('H:i'));
        self::assertSame('18:00', $next->end->format('H:i'));
        self::assertSame('00:00', $state->lastWindowEnd()->format('H:i'));
        // Approval was at 14:00: activity at 13:30 must still be collected.
        self::assertTrue($next->contains(new \DateTimeImmutable('2026-10-11T13:30:00-04:00')));
        unlink($path);
    }

    public function testLegacyPostedEditionsCountButPendingAndRejectedDoNot(): void
    {
        $path = self::tempFile('legacy-window');
        $state = new StateStore($path);
        $state->putEdition($this->edition('pending', '2026-10-11T17:00:00-04:00', StateStore::STATUS_PENDING));
        $state->putEdition($this->edition('rejected', '2026-10-11T16:00:00-04:00', StateStore::STATUS_REJECTED));
        self::assertNull($state->publishedWindowEnd());
        $state->putEdition($this->edition('posted', '2026-10-11T13:00:00-04:00'));
        self::assertSame('13:00', $state->publishedWindowEnd()->format('H:i'));
        $future = $this->window('2026-10-11T18:00:00-04:00', '2026-10-12T18:00:00-04:00');
        self::assertSame($future, $state->unpublishedWindow($future));
        unlink($path);
    }

    public function testBothManualAndScheduledRunsCollectOnlyNewActivityAndKeepHistory(): void
    {
        foreach ([true, false] as $manual) {
            $path = self::tempFile('pipeline-window');
            $state = new StateStore($path);
            $early = $this->edition('early', '2026-10-11T13:00:00-04:00');
            $state->putEdition($early);
            $state->recordPublished($early);
            $source = new class implements Source {
                public array $windows = [];
                public function name(): string
                {
                    return 'github';
                }
                public function collect(Window $window): PromiseInterface
                {
                    $this->windows[] = $window;
                    $facts = [];
                    foreach (['2026-10-11T12:00:00-04:00' => 'Old invite fix', '2026-10-11T15:00:00-04:00' => 'Expanded invite fix with expiry validation'] as $time => $fact) {
                        if ($window->contains(new \DateTimeImmutable($time))) {
                            $facts[] = $fact;
                        }
                    }
                    return resolve(new SourceReport('github', 'GitHub', $facts));
                }
            };
            $reply = "# Invite follow-up\nI expanded the earlier fix with expiry validation.";
            $writer = new Writer($this->fakeLlm(['Expiry validation follow-up.', $reply, $reply]), 'Alex');
            $pipeline = new Pipeline([$source], $writer, $state);
            $edition = self::settle($pipeline->run($this->window('2026-10-11T00:00:00-04:00', '2026-10-11T18:00:00-04:00'), $manual));
            self::assertSame('13:00', $source->windows[0]->start->format('H:i'));
            self::assertSame(['Expanded invite fix with expiry validation'], $edition['reports'][0]['highlights']);
            self::assertSame('2026-10-11T13:00:00-04:00', $edition['window']['start']);
            self::assertSame('Earlier fix', $pipeline->previous()[0]['headline']);
            self::assertStringContainsString('write only about what is new', $this->prompts[count($this->prompts) - 2]);
            unlink($path);
        }
    }

    public function testStalePendingApprovalRejectsBeforeAccessingDiscord(): void
    {
        $path = self::tempFile('stale-approval');
        $state = new StateStore($path);
        $state->putEdition($this->edition('early', '2026-10-11T13:00:00-04:00'));
        $state->putEdition($this->edition('stale', '2026-10-11T18:00:00-04:00', StateStore::STATUS_PENDING));
        // Leave Discord uninitialized: any transport access would fail this test.
        $reflection = new \ReflectionClass(\Newsletter\Bot\ApprovalFlow::class);
        $flow = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('state')->setValue($flow, $state);
        $reflection->getProperty('tz')->setValue($flow, new \DateTimeZone('America/New_York'));
        $this->expectExceptionMessage('overlaps activity already posted');
        try {
            self::settle($flow->approve('stale'));
        } finally {
            self::assertSame(StateStore::STATUS_PENDING, $state->edition('stale')['status']);
            unlink($path);
        }
    }

    public function testEntirelyCoveredWindowDoesNotCallSourcesOrModel(): void
    {
        $path = self::tempFile('covered-window');
        $state = new StateStore($path);
        $state->putEdition($this->edition('early', '2026-10-11T13:00:00-04:00'));
        $source = new class implements Source {
            public function name(): string
            {
                return 'github';
            }
            public function collect(Window $window): PromiseInterface
            {
                throw new \LogicException('Should not collect');
            }
        };
        $pipeline = new Pipeline([$source], new Writer($this->fakeLlm([]), 'Alex'), $state);
        $this->expectExceptionMessage('already been published');
        try {
            self::settle($pipeline->run($this->window('2026-10-11T00:00:00-04:00', '2026-10-11T13:00:00-04:00')));
        } finally {
            unlink($path);
        }
    }
}
