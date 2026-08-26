<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use PDO;
use RuntimeException;

final class Connection
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    public $mode;

    public function __construct(PDO $pdo, string $mode)
    {
        $this->pdo = $pdo;
        $this->mode = CompatibilityMode::fromName($mode);
    }

    public function prepare(string $sql, array $resultTypes = []): Statement
    {
        return new Statement($this->pdo->prepare($sql), $this->mode, $resultTypes);
    }

    public function query(string $sql, array $resultTypes = []): Statement
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('PDO returned false while executing a query');
        }
        return new Statement($statement, $this->mode, $resultTypes);
    }

    public function execute(string $sql, array $parameters = [], array $resultTypes = []): Statement
    {
        $statement = $this->prepare($sql, $resultTypes);
        $statement->execute($parameters);
        return $statement;
    }

    public function exec(string $sql): int
    {
        $count = $this->pdo->exec($sql);
        if ($count === false) {
            throw new RuntimeException('PDO returned false while executing SQL');
        }
        return $count;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function nativePdo(): PDO
    {
        return $this->pdo;
    }

    public function assertCompatibilityMode(): void
    {
        $statement = $this->pdo->query(
            'SELECT datcompatibility FROM pg_database WHERE datname = current_database()'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to query GaussDB compatibility mode');
        }
        $actual = $statement->fetchColumn();
        if (!is_string($actual) || !CompatibilityMode::matchesDatabaseValue($this->mode, $actual)) {
            $shown = is_scalar($actual) ? (string) $actual : gettype($actual);
            throw new RuntimeException(
                "Connected database compatibility mode is {$shown}; expected {$this->mode}"
            );
        }
    }

    public function assertUtf8ClientEncoding(): void
    {
        $statement = $this->pdo->query('SHOW client_encoding');
        if ($statement === false) {
            throw new RuntimeException('Unable to query GaussDB client encoding');
        }
        $encoding = $statement->fetchColumn();
        $normalized = strtoupper(str_replace(['-', '_'], '', (string) $encoding));
        if ($normalized !== 'UTF8') {
            throw new RuntimeException("GaussDB ODBC client encoding is not UTF8: {$encoding}");
        }
    }
}
