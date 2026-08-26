<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use PDO;
use PDOStatement;
use UnexpectedValueException;

final class Statement
{
    /** @param array<int|string, ResultType|string> $resultTypes */
    public function __construct(
        private readonly PDOStatement $statement,
        private readonly CompatibilityMode $mode,
        private readonly array $resultTypes = [],
    ) {
    }

    public function execute(array $parameters = []): bool
    {
        foreach ($parameters as $key => $value) {
            $parameter = is_int($key)
                ? $key + 1
                : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            [$normalized, $pdoType] = $this->normalizeParameter($value);
            $this->statement->bindValue($parameter, $normalized, $pdoType);
        }
        return $this->statement->execute();
    }

    public function bindValue(int|string $parameter, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if ($value instanceof BinaryValue || is_bool($value)) {
            [$value, $type] = $this->normalizeParameter($value);
        }
        return $this->statement->bindValue($parameter, $value, $type);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT): mixed
    {
        $row = $this->statement->fetch($mode);
        if (is_array($row)) {
            return $this->normalizeRow($row);
        }
        if ($row !== false && $mode === PDO::FETCH_COLUMN && isset($this->resultTypes[0])) {
            return self::normalizeResult($row, $this->resultTypes[0]);
        }
        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array
    {
        $rows = $this->statement->fetchAll($mode);
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = $this->normalizeRow($row);
            } elseif ($mode === PDO::FETCH_COLUMN && isset($this->resultTypes[0])) {
                $rows[$index] = self::normalizeResult($row, $this->resultTypes[0]);
            }
        }
        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = $this->statement->fetchColumn($column);
        $type = $this->resultTypes[$column] ?? null;
        return $type === null || $value === false ? $value : self::normalizeResult($value, $type);
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    public function closeCursor(): bool
    {
        return $this->statement->closeCursor();
    }

    public function nativeStatement(): PDOStatement
    {
        return $this->statement;
    }

    private function normalizeParameter(mixed $value): array
    {
        return match (true) {
            $value instanceof BinaryValue && $this->mode === CompatibilityMode::ORACLE => [
                strtoupper(bin2hex($value->bytes)),
                PDO::PARAM_STR,
            ],
            $value instanceof BinaryValue => [$value->bytes, PDO::PARAM_LOB],
            is_bool($value) => [$value ? 1 : 0, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            $value === null => [null, PDO::PARAM_NULL],
            default => [$value, PDO::PARAM_STR],
        };
    }

    private function normalizeRow(array $row): array
    {
        foreach ($this->resultTypes as $column => $type) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = self::normalizeResult($row[$column], $type);
            }
        }
        return $row;
    }

    private static function normalizeResult(mixed $value, ResultType|string $type): mixed
    {
        $type = is_string($type) ? ResultType::from($type) : $type;
        return match ($type) {
            ResultType::BOOLEAN => self::toBoolean($value),
            ResultType::BINARY_HEX => self::decodeHexBinary($value),
        };
    }

    private static function toBoolean(mixed $value): bool
    {
        if (in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'f', 'false', 'FALSE'], true)) {
            return false;
        }
        throw new UnexpectedValueException('GaussDB boolean result is not a recognized 0/1 value');
    }

    private static function decodeHexBinary(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('GaussDB binary result is not string or stream data');
        }
        if (str_starts_with($value, '\\x')) {
            $value = substr($value, 2);
        }
        if ($value === '') {
            return '';
        }
        if (strlen($value) % 2 !== 0 || !ctype_xdigit($value)) {
            throw new UnexpectedValueException('GaussDB ODBC binary result is not hexadecimal text');
        }
        $decoded = hex2bin($value);
        if ($decoded === false) {
            throw new UnexpectedValueException('Unable to decode GaussDB ODBC binary result');
        }
        return $decoded;
    }
}
