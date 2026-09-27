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

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use Newsletter\Draft;
use Newsletter\Window;

/**
 * Turns drafts into Components V2 messages: the public newsletter, and the
 * approval request DM'd to the owner (the same newsletter plus status text and
 * Approve / Request edits / Skip buttons).
 *
 * A V2 message allows at most 4000 characters of text across all its text
 * displays, so section bodies are trimmed (longest first) to fit.
 *
 * @since 1.0.0
 */
final class Renderer
{
    /** Discord's cap on text across a Components V2 message, less some headroom. */
    public const TEXT_BUDGET = 3900;

    /** Prefix of every button custom id this bot owns: `newsletter:{action}:{key}:{revision}`. */
    public const PREFIX = 'newsletter';

    /** Accent colour of the newsletter card. */
    private const ACCENT = 0x5865F2;

    /** The newsletter as it is posted in the guild channel. */
    public static function newsletter(Draft $draft, Window $window): MessageBuilder
    {
        return self::builder()->addComponent(self::card($draft, "-# Daily recap · {$window->label()}", self::TEXT_BUDGET));
    }

    /**
     * The approval request for a stored edition.
     *
     * @param array<string, mixed> $edition
     * @param list<string>         $sites   Repositories an approval also publishes to.
     */
    public static function approval(array $edition, Window $window, array $sites = []): MessageBuilder
    {
        $draft = Draft::fromArray($edition['draft']);
        $revision = (int) $edition['revision'];

        $header = "📰 **Newsletter draft for {$window->label()}** · revision {$revision}\n"
            . 'Reply to this message with any edits you want (in plain words), or use the buttons.';
        if ($sites) {
            $header .= "\n-# Approving also publishes it to " . implode(', ', $sites) . '.';
        }
        foreach ((array) ($edition['reports'] ?? []) as $report) {
            foreach ((array) ($report['errors'] ?? []) as $error) {
                $header .= "\n-# ⚠️ {$report['source']}: " . mb_substr((string) $error, 0, 180);
            }
        }
        if ($last = end($edition['history'])) {
            $header .= "\n-# Last edit request: " . mb_substr((string) $last['instructions'], 0, 200);
        }

        $key = (string) $edition['key'];
        $id = static fn(string $action): string => self::PREFIX . ":{$action}:{$key}:{$revision}";

        return self::builder()
            ->addComponent(TextDisplay::new($header))
            ->addComponent(self::card($draft, "-# Daily recap · {$window->label()}", self::TEXT_BUDGET - mb_strlen($header)))
            ->addComponent(ActionRow::new()
                ->addComponent(Button::success($id('approve'))->setLabel('Approve & post')->setEmoji('✅'))
                ->addComponent(Button::primary($id('edit'))->setLabel('Request edits')->setEmoji('✏️'))
                ->addComponent(Button::danger($id('reject'))->setLabel('Skip this one')->setEmoji('🗑️')));
    }

    /**
     * Parses a custom id this renderer produced.
     *
     * @return array{action: string, key: string, revision: int}|null
     */
    public static function parseCustomId(string $customId): ?array
    {
        if (! preg_match('/^' . self::PREFIX . ':(approve|edit|reject):([\w-]+):(\d+)$/', $customId, $m)) {
            return null;
        }

        return ['action' => $m[1], 'key' => $m[2], 'revision' => (int) $m[3]];
    }

    /**
     * The text blocks of a draft, trimmed so their total fits `$budget`.
     *
     * @return list<string>
     */
    public static function blocks(Draft $draft, string $footer, int $budget): array
    {
        $head = "# {$draft->headline}" . ($draft->intro !== '' ? "\n{$draft->intro}" : '');
        $sections = array_map(static fn(array $s) => ($s['title'] !== '' ? "## {$s['title']}\n" : '') . $s['body'], $draft->sections);
        $tail = trim($draft->signoff . "\n" . $footer);

        // Takes $sections as an argument: an arrow fn would capture a stale copy.
        $total = static fn(array $sections): int => mb_strlen($head) + array_sum(array_map('mb_strlen', $sections)) + mb_strlen($tail);
        while ($total($sections) > $budget && $sections) {
            $lengths = array_map('mb_strlen', $sections);
            arsort($lengths);
            $longest = array_key_first($lengths);
            $over = $total($sections) - $budget;
            $keep = max(80, $lengths[$longest] - $over - 1);
            if ($keep >= $lengths[$longest]) {
                array_splice($sections, $longest, 1); // every section is already minimal
                continue;
            }
            $sections[$longest] = rtrim(mb_substr($sections[$longest], 0, $keep)) . '…';
        }

        return array_values(array_filter([$head, ...$sections, $tail], static fn($b) => trim($b) !== ''));
    }

    private static function card(Draft $draft, string $footer, int $budget): Container
    {
        $blocks = self::blocks($draft, $footer, $budget);
        $container = Container::new()->setAccentColor(self::ACCENT);
        $last = count($blocks) - 1;
        foreach ($blocks as $i => $block) {
            $container->addComponent(TextDisplay::new($block));
            if ($i === 0 || $i === $last - 1) {
                $container->addComponent(Separator::new());
            }
        }

        return $container;
    }

    private static function builder(): MessageBuilder
    {
        return MessageBuilder::new()->setAllowedMentions(AllowedMentions::none());
    }
}
