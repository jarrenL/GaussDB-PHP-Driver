<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use InvalidArgumentException;

final class CompatibilityMode
{
    const M = 'M';
    const ORACLE = 'ORA';

    public static function fromName(string $mode): string
    {
        $normalized = strtoupper(trim($mode));
        if ($normalized === 'M') {
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
            return $actual === 'M';
        }
        if ($mode === self::ORACLE) {
            return in_array($actual, array('A', 'O', 'ORA', 'ORACLE'), true);
        }
        return false;
    }
}
