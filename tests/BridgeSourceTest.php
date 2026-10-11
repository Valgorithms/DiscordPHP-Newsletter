<?php

declare(strict_types=1);

namespace Tests;

use Newsletter\Sources\BridgeSource;
use Newsletter\Sources\DeveloperContextSource;
use Newsletter\Sources\LocalContextFile;
use Newsletter\Sources\SourceReport;
use Newsletter\Window;

use function React\Promise\resolve;

final class BridgeSourceTest extends TestCase
{
    public function testThePublishedCutoffExcludesOldAndEndBoundaryMessages(): void
    {
        $message = ['platform' => 'telegram', 'room' => 'public', 'author_id' => 'owner', 'id' => '1', 'text' => 'I plan to release next week.', 'created_at' => '2026-10-11T09:00:00Z', 'updated_at' => '2026-10-11T09:00:00Z'];
        $during = array_replace($message, ['id' => '2', 'updated_at' => '2026-10-11T11:00:00Z', 'revised' => true]);
        $end = array_replace($message, ['id' => '3', 'updated_at' => '2026-10-11T12:00:00Z']);
        $source = new BridgeSource(static fn() => resolve(['version' => 1, 'coverage_start' => '2026-10-11T00:00:00Z', 'updated_at' => '2026-10-11T12:00:00Z', 'messages' => [$message, $during, $during, $end]]));

        $report = self::settle($source->collect($this->window()));
        self::assertCount(1, $report->highlights);
        self::assertStringContainsString('revised an earlier message', $report->highlights[0]);
        self::assertStringContainsString('self-reported conversation', $report->highlights[0]);
        self::assertSame([], $report->errors);
    }

    public function testMissingOrStaleFeedDoesNotEstablishInactivity(): void
    {
        $source = new BridgeSource(static fn() => resolve(['version' => 1, 'coverage_start' => '2026-10-11T11:00:00Z', 'updated_at' => '2026-10-11T11:00:00Z', 'messages' => []]));
        self::assertNotEmpty(self::settle($source->collect($this->window()))->errors);
        $source = new BridgeSource(static fn() => LocalContextFile::read(__DIR__ . '/absent-feed.json'));
        self::assertStringContainsString('unavailable', self::settle($source->collect($this->window()))->toPromptText());
    }

    public function testBackgroundRoundTripRemainsContextRatherThanActivity(): void
    {
        $source = new DeveloperContextSource(static fn() => resolve(['version' => 1, 'facts' => [['statement' => 'Project is a chat bridge.', 'source_url' => 'https://example.com/project'], ['statement' => 'Unsourced claim']]]));
        $report = self::settle($source->collect($this->window()));
        self::assertCount(1, $report->highlights);
        self::assertTrue($report->isEmpty(), 'Background alone must never create a new activity section.');
        self::assertStringContainsString('BACKGROUND ONLY', $report->toPromptText());
        self::assertTrue(SourceReport::fromArray($report->toArray())->contextOnly);
        self::assertFalse(SourceReport::fromArray(['source' => 'legacy'])->contextOnly);
    }

    public function testInvalidAndOverlargeLocalFilesFailWithoutLeakingTheirContents(): void
    {
        $path = self::tempFile('context');
        file_put_contents($path, 'private unparseable context');
        $source = new DeveloperContextSource(static fn() => LocalContextFile::read($path));
        $report = self::settle($source->collect($this->window()));
        self::assertNotEmpty($report->errors);
        self::assertStringNotContainsString('private unparseable', $report->toPromptText());
        file_put_contents($path, str_repeat('x', 2_000_001));
        self::assertNotEmpty(self::settle($source->collect($this->window()))->errors);
    }

    private function window(): Window
    {
        return new Window(new \DateTimeImmutable('2026-10-11T10:00:00Z'), new \DateTimeImmutable('2026-10-11T12:00:00Z'));
    }
}
