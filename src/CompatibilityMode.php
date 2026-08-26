<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use InvalidArgumentException;

enum CompatibilityMode: string
{
    case M = 'M';
    case ORACLE = 'ORA';

    public static function fromName(string $mode): self
    {
        return match (strtoupper(trim($mode))) {
            'M' => self::M,
            'A', 'O', 'ORA', 'ORACLE' => self::ORACLE,
            default => throw new InvalidArgumentException("Unsupported GaussDB compatibility mode: {$mode}"),
        };
    }

    public function matchesDatabaseValue(string $value): bool
    {
        $actual = strtoupper(trim($value));
        return match ($this) {
            self::M => $actual === 'M',
            self::ORACLE => in_array($actual, ['A', 'O', 'ORA', 'ORACLE'], true),
        };
    }
}
