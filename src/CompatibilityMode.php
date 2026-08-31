<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use InvalidArgumentException;

final class CompatibilityMode
{
    /** PHP 7.2-compatible string constants; this class is not a PHP enum. */
    const M = 'M';
    const ORACLE = 'ORA';

    public static function fromName(string $mode): string
    {
        $normalized = strtoupper(trim($mode));
        if (in_array($normalized, array('M', 'MYSQL'), true)) {
            return self::M;
        }
        if (in_array($normalized, array('A', 'O', 'ORA', 'ORACLE'), true)) {
            return self::ORACLE;
        }
        throw new InvalidArgumentException("Unsupported GaussDB compatibility mode: {$mode}");
    }

    public static function matchesDatabaseValue(string $mode, string $value): bool
    {
        $actual = strtoupper(trim($value));
        if ($mode === self::M) {
            return in_array($actual, array('M', 'MYSQL'), true);
        }
        if ($mode === self::ORACLE) {
            return in_array($actual, array('A', 'O', 'ORA', 'ORACLE'), true);
        }
        return false;
    }
}
