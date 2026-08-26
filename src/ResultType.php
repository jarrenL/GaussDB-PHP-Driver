<?php

declare(strict_types=1);

namespace GaussDb\Compat;

enum ResultType: string
{
    /** GaussDB ODBC exposes VARBINARY/BLOB values as hexadecimal text. */
    case BINARY_HEX = 'binary_hex';

    /** Normalize M BOOLEAN or ORA NUMBER(1) 0/1 values to PHP bool. */
    case BOOLEAN = 'boolean';
}
