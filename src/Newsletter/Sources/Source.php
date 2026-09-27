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

namespace Newsletter\Sources;

use Newsletter\Window;
use React\Promise\PromiseInterface;

/**
 * One place the owner's activity comes from.
 *
 * @since 1.0.0
 */
interface Source
{
    /** Machine name, e.g. `github`. */
    public function name(): string;

    /**
     * Gathers everything inside `$window`.
     *
     * Implementations should resolve with a report carrying `errors` for
     * partial failures rather than rejecting; the pipeline still guards against
     * a rejection so one broken API never sinks the whole edition.
     *
     * @return PromiseInterface<SourceReport>
     */
    public function collect(Window $window): PromiseInterface;
}
