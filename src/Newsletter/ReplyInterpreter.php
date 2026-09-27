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

use Newsletter\Llm\OllamaClient;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Decides what a free-text DM reply to a draft means: approve it, reject it,
 * or revise it (and how).
 *
 * Unambiguous one-word answers ("approve", "lgtm", "✅", "skip") are handled
 * without the model; anything else goes to the local LLM, and if the model is
 * unavailable the whole message is treated as edit instructions, which is the
 * safe reading. `via` says which path decided (`quick`, `llm` or `fallback`),
 * so the caller can insist on an explicit approval before posting anything
 * the model merely *inferred* was approved.
 *
 * @since 1.0.0
 */
final class ReplyInterpreter
{
    public const APPROVE = 'approve';
    public const REJECT = 'reject';
    public const EDIT = 'edit';

    private const APPROVALS = ['approve', 'approved', 'approve it', 'lgtm', 'looks good', 'looks good to me', 'ship it', 'post it', 'post', 'send it', 'publish', 'publish it', 'yes', 'y', 'ok', 'okay', 'perfect', 'good to go', '👍', '✅', '✔️', '🚀'];
    private const REJECTIONS = ['reject', 'rejected', 'skip', 'skip it', 'skip today', 'cancel', 'discard', 'drop it', "don't post", 'do not post', 'no', 'n', 'nope', '❌', '👎', '🗑️'];

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => [self::APPROVE, self::REJECT, self::EDIT]],
            'instructions' => ['type' => 'string'],
        ],
        'required' => ['action', 'instructions'],
    ];

    public function __construct(private readonly ?OllamaClient $llm = null) {}

    /**
     * The fast path alone: an action for an unambiguous reply, else `null`.
     *
     * @return array{action: string, instructions: string, via: string}|null
     */
    public static function quick(string $reply): ?array
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[\s.!]+$/u', '', trim($reply))));
        if (in_array($normalized, self::APPROVALS, true)) {
            return ['action' => self::APPROVE, 'instructions' => '', 'via' => 'quick'];
        }
        if (in_array($normalized, self::REJECTIONS, true)) {
            return ['action' => self::REJECT, 'instructions' => '', 'via' => 'quick'];
        }

        return null;
    }

    /** @return PromiseInterface<array{action: string, instructions: string, via: string}> */
    public function interpret(string $reply): PromiseInterface
    {
        if (($quick = self::quick($reply)) !== null) {
            return resolve($quick);
        }
        $asEdit = ['action' => self::EDIT, 'instructions' => trim($reply), 'via' => 'fallback'];
        if ($this->llm === null) {
            return resolve($asEdit);
        }

        return $this->llm->chat([
            ['role' => 'system', 'content' => 'You classify replies to a newsletter approval request. Reply with JSON only.'],
            ['role' => 'user', 'content' => <<<PROMPT
                The author was sent a draft newsletter and asked to approve it, reject it, or request edits. They replied:

                """{$reply}"""

                Classify the reply:
                - "approve": they want it posted as-is (e.g. "looks great, send it").
                - "reject": they do not want anything posted for this day (e.g. "nah, skip today").
                - "edit": they want any change at all, even if they also sound positive (e.g. "good, but drop the Steam part").
                For "edit", put a clear, complete instruction for the editor in "instructions" (keep all their requested changes).
                Otherwise use an empty string.
                PROMPT],
        ], self::SCHEMA, 0.0)->then(
            static function (string $raw) use ($asEdit): array {
                $decoded = json_decode($raw, true);
                $action = is_array($decoded) ? ($decoded['action'] ?? null) : null;
                if (! in_array($action, [self::APPROVE, self::REJECT, self::EDIT], true)) {
                    return $asEdit;
                }
                $instructions = trim((string) ($decoded['instructions'] ?? ''));

                return ['action' => $action, 'instructions' => $action === self::EDIT ? ($instructions ?: $asEdit['instructions']) : '', 'via' => 'llm'];
            },
            static fn(): array => $asEdit,
        );
    }
}
