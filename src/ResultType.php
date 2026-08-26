<?php

declare(strict_types=1);

namespace GaussDb\Compat;

final class ResultType
{
    /** GaussDB ODBC exposes binary/BLOB values as hexadecimal text. */
    const BINARY_HEX = 'binary_hex';

    /** Normalize M BOOLEAN or ORA NUMBER(1) 0/1 values to PHP bool. */
    const BOOLEAN = 'boolean';

    public static function validate(string $type): string
    {
        if ($type !== self::BINARY_HEX && $type !== self::BOOLEAN) {
            throw new \InvalidArgumentException("Unsupported GaussDB result type: {$type}");
        }
        return $type;
    }
}
