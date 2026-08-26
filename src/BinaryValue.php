<?php

declare(strict_types=1);

namespace GaussDb\Compat;

final readonly class BinaryValue
{
    public function __construct(public string $bytes)
    {
    }
}
