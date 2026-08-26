<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use InvalidArgumentException;

final readonly class ConnectionConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public CompatibilityMode $mode,
        public string $driver = 'GaussDB Unicode',
        public string $sslMode = 'prefer',
        public ?string $dsn = null,
        public array $pdoOptions = [],
    ) {
        if ($host === '' || $database === '' || $user === '') {
            throw new InvalidArgumentException('Host, database, and user must not be empty');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port: {$port}");
        }
    }

    public function pdoDsn(): string
    {
        if ($this->dsn !== null && $this->dsn !== '') {
            return str_starts_with($this->dsn, 'odbc:') ? $this->dsn : 'odbc:' . $this->dsn;
        }

        $parts = [
            'Driver={' . str_replace('}', '}}', $this->driver) . '}',
            'Servername=' . self::odbcScalar($this->host),
            'Port=' . $this->port,
            'Database=' . self::odbcScalar($this->database),
            'SSLmode=' . self::odbcScalar($this->sslMode),
            'ConnSettings=set client_encoding=UTF8',
            'BoolsAsChar=0',
            'ByteaAsLongVarBinary=1',
        ];

        return 'odbc:' . implode(';', $parts);
    }

    private static function odbcScalar(string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('ODBC connection values must not contain semicolons or NUL bytes');
        }
        return $value;
    }
}
