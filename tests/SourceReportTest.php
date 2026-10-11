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

use Newsletter\Sources\SourceReport;

final class SourceReportTest extends TestCase
{
    public function testUnavailableActivityIsNotReportedAsNoActivity(): void
    {
        $failed = SourceReport::failed('github', 'GitHub', 'unavailable');
        $this->assertTrue($failed->isEmpty());
        $this->assertStringContainsString('(activity data unavailable)', $failed->toPromptText());
        $this->assertStringNotContainsString('(no activity', $failed->toPromptText());
        $this->assertStringContainsString('(no activity recorded)', (new SourceReport('github', 'GitHub'))->toPromptText());
    }

    public function testOmittedItemsAreNotAssumedToBeSimilar(): void
    {
        $report = new SourceReport('github', 'GitHub', ['Fixed invite parsing', 'Announced an unrelated project']);
        $prompt = $report->toPromptText(1);
        $this->assertStringContainsString('Fixed invite parsing', $prompt);
        $this->assertStringNotContainsString('Announced an unrelated project', $prompt);
        $this->assertStringContainsString('1 additional items omitted', $prompt);
        $this->assertStringNotContainsString('similar', $prompt);
    }
}
