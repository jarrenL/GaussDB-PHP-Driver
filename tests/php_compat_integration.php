<?php

declare(strict_types=1);

use GaussDb\Compat\BinaryValue;
use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Driver;
use GaussDb\Compat\ResultType;

require dirname(__DIR__) . '/src/autoload.php';

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("{$name} is required");
    }
    return $value;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mode = CompatibilityMode::fromName(requiredEnv('GAUSS_MODE'));
$database = requiredEnv('GAUSS_DATABASE');
$connection = Driver::connect(new ConnectionConfig(
    getenv('GAUSS_HOST') ?: 'host.docker.internal',
    (int) (getenv('GAUSS_PORT') ?: 5432),
    $database,
    requiredEnv('GAUSS_USER'),
    requiredEnv('GAUSS_PASSWORD'),
    $mode
));

$suffix = substr(hash('sha256', $database . ':' . bin2hex(random_bytes(8))), 0, 12);
$table = 'php_compat_' . $suffix;
$tests = [];

$run = static function (string $name, callable $test) use (&$tests): void {
    $started = microtime(true);
    try {
        $detail = $test();
        $tests[] = ['name' => $name, 'status' => 'pass', 'duration_ms' => round((microtime(true) - $started) * 1000, 3), 'detail' => $detail];
    } catch (Throwable $error) {
        $tests[] = [
            'name' => $name,
            'status' => 'fail',
            'duration_ms' => round((microtime(true) - $started) * 1000, 3),
            'detail' => ['exception' => get_class($error), 'sqlstate' => (string) $error->getCode(), 'message' => $error->getMessage()],
        ];
    }
};

try {
    $connection->exec("DROP TABLE IF EXISTS {$table}");
    if ($mode === CompatibilityMode::M) {
        $connection->exec("CREATE TABLE {$table} (
            id BIGINT PRIMARY KEY,
            name VARCHAR(256) NOT NULL,
            amount DECIMAL(20,4),
            enabled BOOLEAN,
            payload BLOB,
            note VARCHAR(256),
            created_at TIMESTAMP
        )");
    } else {
        $connection->exec("CREATE TABLE {$table} (
            id NUMBER(20) PRIMARY KEY,
            name VARCHAR2(256) NOT NULL,
            amount NUMBER(20,4),
            enabled NUMBER(1),
            payload RAW(256),
            note VARCHAR2(256),
            created_at TIMESTAMP
        )");
    }

    $run('prepared CRUD and scalar types', static function () use ($connection, $table): array {
        $connection->execute(
            "INSERT INTO {$table} (id, name, amount, enabled, note, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [1, 'basic', '1234567890123456.7890', true, null, '2026-08-26 12:34:56']
        );
        $row = $connection->execute(
            "SELECT id, name, amount, enabled, note, created_at FROM {$table} WHERE id = ?",
            [1],
            ['enabled' => ResultType::BOOLEAN]
        )->fetch();
        check(is_array($row), 'Inserted row was not returned');
        check((string) $row['id'] === '1', 'Integer value changed');
        check((string) $row['amount'] === '1234567890123456.7890', 'Decimal precision changed');
        check($row['enabled'] === true, 'Boolean value was not normalized');
        check($row['note'] === null, 'NULL value changed');

        $connection->execute(
            "INSERT INTO {$table} (id, name, enabled) VALUES (?, ?, ?)",
            [6, 'false-value', false]
        );
        $disabled = $connection->execute(
            "SELECT enabled FROM {$table} WHERE id = ?",
            [6],
            [0 => ResultType::BOOLEAN]
        )->fetchColumn();
        check($disabled === false, 'Boolean false was not normalized');
        return $row;
    });

    $run('UTF-8 Chinese and emoji', static function () use ($connection, $table): void {
        $value = 'GaussDB 中文与 emoji 🚀';
        $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [2, $value]);
        $actual = $connection->execute("SELECT name FROM {$table} WHERE id = ?", [2])->fetchColumn();
        check($actual === $value, 'UTF-8 value changed');
    });

    $run('binary value containing NUL', static function () use ($connection, $table): array {
        $value = "A\x00B\xFFZ";
        $connection->execute(
            "INSERT INTO {$table} (id, name, payload) VALUES (?, ?, ?)",
            [3, 'binary', new BinaryValue($value)]
        );
        $actual = $connection->execute(
            "SELECT payload FROM {$table} WHERE id = ?",
            [3],
            [0 => ResultType::BINARY_HEX]
        )->fetchColumn();
        check($actual === $value, 'Binary value changed');
        return ['bytes' => strlen($actual), 'sha256' => hash('sha256', $actual)];
    });

    $run('bound value resists SQL injection', static function () use ($connection, $table): void {
        $value = "x'); DROP TABLE {$table}; --";
        $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [4, $value]);
        $actual = $connection->execute("SELECT name FROM {$table} WHERE id = ?", [4])->fetchColumn();
        check($actual === $value, 'Bound value changed or was executed as SQL');
    });

    $run('prepared statement reuse and rowCount', static function () use ($connection, $table): void {
        $insert = $connection->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        foreach ([10 => 'reuse-a', 11 => 'reuse-b', 12 => 'reuse-c'] as $id => $name) {
            $insert->execute([$id, $name]);
            check($insert->rowCount() === 1, 'INSERT rowCount is not 1');
        }
        $count = $connection->execute("SELECT COUNT(*) FROM {$table} WHERE id BETWEEN ? AND ?", [10, 12])->fetchColumn();
        check((int) $count === 3, 'Prepared statement reuse failed');
    });

    $run('named parameters and mapped fetchAll', static function () use ($connection, $table): void {
        $name = $connection->execute(
            "SELECT name FROM {$table} WHERE id = :id",
            ['id' => 1]
        )->fetchColumn();
        check($name === 'basic', 'Named parameter returned the wrong row');

        $rows = $connection->execute(
            "SELECT id, enabled FROM {$table} WHERE id IN (?, ?) ORDER BY id",
            [1, 6],
            ['enabled' => ResultType::BOOLEAN]
        )->fetchAll();
        check(count($rows) === 2, 'Mapped fetchAll returned the wrong row count');
        check($rows[0]['enabled'] === true && $rows[1]['enabled'] === false, 'Mapped fetchAll boolean values changed');
    });

    $run('update delete and affected row counts', static function () use ($connection, $table): void {
        $update = $connection->execute("UPDATE {$table} SET note = ? WHERE id = ?", ['updated', 1]);
        check($update->rowCount() === 1, 'UPDATE rowCount is not 1');
        $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [7, 'delete-me']);
        $delete = $connection->execute("DELETE FROM {$table} WHERE id = ?", [7]);
        check($delete->rowCount() === 1, 'DELETE rowCount is not 1');
    });

    $run('transaction rollback and commit', static function () use ($connection, $table): void {
        $connection->beginTransaction();
        $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [20, 'rollback']);
        $connection->rollBack();
        check((int) $connection->execute("SELECT COUNT(*) FROM {$table} WHERE id = ?", [20])->fetchColumn() === 0, 'Rollback failed');

        $connection->beginTransaction();
        $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [21, 'commit']);
        $connection->commit();
        check((int) $connection->execute("SELECT COUNT(*) FROM {$table} WHERE id = ?", [21])->fetchColumn() === 1, 'Commit failed');
    });

    $run('savepoint rollback', static function () use ($connection, $table): void {
        $connection->beginTransaction();
        try {
            $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [30, 'outer']);
            $connection->exec('SAVEPOINT php_compat_sp');
            $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [31, 'inner']);
            $connection->exec('ROLLBACK TO SAVEPOINT php_compat_sp');
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        $count = $connection->execute("SELECT COUNT(*) FROM {$table} WHERE id IN (?, ?)", [30, 31])->fetchColumn();
        check((int) $count === 1, 'Savepoint result is incorrect');
    });

    $run('duplicate key SQLSTATE and recovery', static function () use ($connection, $table): array {
        try {
            $connection->execute("INSERT INTO {$table} (id, name) VALUES (?, ?)", [1, 'duplicate']);
            throw new RuntimeException('Duplicate key unexpectedly succeeded');
        } catch (PDOException $error) {
            $sqlstate = (string) $error->getCode();
            check(strlen($sqlstate) === 5, 'Expected a five-character SQLSTATE');
        }
        check((int) $connection->query('SELECT 1')->fetchColumn() === 1, 'Connection did not recover after handled error');
        return ['sqlstate' => $sqlstate];
    });
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $connection->exec("DROP TABLE IF EXISTS {$table}");
}

$failed = 0;
foreach ($tests as $test) {
    if ($test['status'] === 'fail') {
        ++$failed;
    }
}
echo json_encode([
    'mode' => $mode,
    'database' => $database,
    'php' => PHP_VERSION,
    'architecture' => php_uname('m'),
    'summary' => ['pass' => count($tests) - $failed, 'fail' => $failed],
    'tests' => $tests,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
exit($failed === 0 ? 0 : 1);
