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

use Newsletter\ReplyInterpreter;

final class ReplyInterpreterTest extends TestCase
{
    /** @dataProvider quickReplies */
    public function testUnambiguousRepliesSkipTheModel(string $reply, ?string $action): void
    {
        $this->assertSame($action, ReplyInterpreter::quick($reply)['action'] ?? null);
    }

    public static function quickReplies(): array
    {
        return [
            ['Approve!', ReplyInterpreter::APPROVE],
            ['  LGTM ', ReplyInterpreter::APPROVE],
            ['✅', ReplyInterpreter::APPROVE],
            ['skip today.', ReplyInterpreter::REJECT],
            ['yes but drop the steam part', null],
            ['make it shorter', null],
        ];
    }

    public function testModelClassifiesEverythingElse(): void
    {
        $interpreter = new ReplyInterpreter($this->fakeLlm(['{"action":"edit","instructions":"Remove the Steam section."}']));

        $this->assertSame(
            ['action' => 'edit', 'instructions' => 'Remove the Steam section.', 'via' => 'llm'],
            self::settle($interpreter->interpret('looks good but lose the gaming stuff')),
        );
        $this->assertStringContainsString('lose the gaming stuff', $this->prompts[0]);
    }

    public function testInferredApprovalIsMarkedAsComingFromTheModel(): void
    {
        $interpreter = new ReplyInterpreter($this->fakeLlm(['{"action":"approve","instructions":""}']));

        $this->assertSame('llm', self::settle($interpreter->interpret('great work, send it out'))['via']);
    }

    public function testFallsBackToTreatingTheReplyAsEdits(): void
    {
        $broken = new ReplyInterpreter($this->fakeLlm([new \RuntimeException('down')]));
        $garbage = new ReplyInterpreter($this->fakeLlm(['{"action":"dance"}']));
        $none = new ReplyInterpreter();

        foreach ([$broken, $garbage, $none] as $interpreter) {
            $this->assertSame(
                ['action' => 'edit', 'instructions' => 'shorter please', 'via' => 'fallback'],
                self::settle($interpreter->interpret('shorter please')),
            );
        }
    }
}
