<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use InvalidArgumentException;

/**
 * A change the page asked for that can't be made, as a message code and its
 * values. The page shows it in the admin's language (sl_Err_<code> in
 * Messages/), so the server sends no prose.
 */
final class EditorException extends InvalidArgumentException
{
    /**
     * @param array<string, int|string> $params Values for the message's {placeholders}.
     */
    public function __construct(public readonly string $errorCode, public readonly array $params = [])
    {
        parent::__construct($errorCode);
    }
}
