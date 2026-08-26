<?php

declare(strict_types=1);

namespace GaussDb\Compat;

final class BinaryValue
{
    /** @var string */
    public $bytes;

    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }
}
