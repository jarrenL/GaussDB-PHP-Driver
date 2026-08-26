<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use PDO;
use PDOStatement;
use UnexpectedValueException;

final class Statement
{
    /** @var PDOStatement */
    private $statement;

    /** @var string */
    private $mode;

    /** @var array<int|string, string> */
    private $resultTypes;

    /** @param array<int|string, string> $resultTypes */
    public function __construct(PDOStatement $statement, string $mode, array $resultTypes = array())
    {
        $this->statement = $statement;
        $this->mode = CompatibilityMode::fromName($mode);
        $this->resultTypes = $resultTypes;
    }

    public function execute(array $parameters = []): bool
    {
        foreach ($parameters as $key => $value) {
            $parameter = is_int($key)
                ? $key + 1
                : (strncmp((string) $key, ':', 1) === 0 ? (string) $key : ':' . $key);
            [$normalized, $pdoType] = $this->normalizeParameter($value);
            $this->statement->bindValue($parameter, $normalized, $pdoType);
        }
        return $this->statement->execute();
    }

    /** @param int|string $parameter @param mixed $value */
    public function bindValue($parameter, $value, int $type = PDO::PARAM_STR): bool
    {
        if ($value instanceof BinaryValue || is_bool($value)) {
            [$value, $type] = $this->normalizeParameter($value);
        }
        return $this->statement->bindValue($parameter, $value, $type);
    }

    /** @param int|null $mode @return mixed */
    public function fetch($mode = null)
    {
        $row = $mode === null ? $this->statement->fetch() : $this->statement->fetch($mode);
        if (is_array($row)) {
            return $this->normalizeRow($row);
        }
        if ($row !== false && $mode === PDO::FETCH_COLUMN && isset($this->resultTypes[0])) {
            return self::normalizeResult($row, $this->resultTypes[0]);
        }
        return $row;
    }

    /** @param int|null $mode */
    public function fetchAll($mode = null): array
    {
        $rows = $mode === null ? $this->statement->fetchAll() : $this->statement->fetchAll($mode);
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = $this->normalizeRow($row);
            } elseif ($mode === PDO::FETCH_COLUMN && isset($this->resultTypes[0])) {
                $rows[$index] = self::normalizeResult($row, $this->resultTypes[0]);
            }
        }
        return $rows;
    }

    /** @return mixed */
    public function fetchColumn(int $column = 0)
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

    /** @param mixed $value */
    private function normalizeParameter($value): array
    {
        if ($value instanceof BinaryValue && $this->mode === CompatibilityMode::ORACLE) {
            return array(
                strtoupper(bin2hex($value->bytes)),
                PDO::PARAM_STR
            );
        }
        if ($value instanceof BinaryValue) {
            return array($value->bytes, PDO::PARAM_LOB);
        }
        if (is_bool($value)) {
            return array($value ? 1 : 0, PDO::PARAM_INT);
        }
        if (is_int($value)) {
            return array($value, PDO::PARAM_INT);
        }
        if ($value === null) {
            return array(null, PDO::PARAM_NULL);
        }
        return array($value, PDO::PARAM_STR);
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

    /** @param mixed $value @return mixed */
    private static function normalizeResult($value, string $type)
    {
        $type = ResultType::validate($type);
        if ($type === ResultType::BOOLEAN) {
            return self::toBoolean($value);
        }
        return self::decodeHexBinary($value);
    }

    /** @param mixed $value */
    private static function toBoolean($value): bool
    {
        if (in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'f', 'false', 'FALSE'], true)) {
            return false;
        }
        throw new UnexpectedValueException('GaussDB boolean result is not a recognized 0/1 value');
    }

    /** @param mixed $value */
    private static function decodeHexBinary($value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('GaussDB binary result is not string or stream data');
        }
        if (strncmp($value, '\\x', 2) === 0) {
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
