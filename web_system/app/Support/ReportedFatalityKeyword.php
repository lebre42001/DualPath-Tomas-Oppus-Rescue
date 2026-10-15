<?php

namespace App\Support;

/**
 * A conservative queue-priority signal only. It never confirms that a death occurred.
 */
final class ReportedFatalityKeyword
{
    public static function matches(?string $description): bool
    {
        return is_string($description)
            && preg_match('/(?<![\p{L}\p{N}])patay(?![\p{L}\p{N}])/iu', $description) === 1;
    }
}
