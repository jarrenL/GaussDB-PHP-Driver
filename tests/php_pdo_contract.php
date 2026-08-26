<?php

declare(strict_types=1);

final class ContractRunner
{
    private array $results = [];

    public function test(string $category, string $name, callable $test, bool $required = true): void
    {
        $started = hrtime(true);
        try {
            $detail = $test();
            $this->results[] = $this->result($category, $name, 'pass', $required, $detail, $started);
        } catch (Throwable $error) {
            $this->results[] = $this->result(
                $category,
                $name,
                $required ? 'fail' : 'compatibility-fail',
                $required,
                ['exception' => get_class($error), 'code' => (string) $error->getCode(), 'message' => $error->getMessage()],
                $started
            );
        }
    }

    public function report(array $environment): int
    {
        $counts = ['pass' => 0, 'fail' => 0, 'compatibility-fail' => 0];
        foreach ($this->results as $result) {
            $counts[$result['status']]++;
        }
        echo json_encode(
            ['environment' => $environment, 'summary' => $counts, 'tests' => $this->results],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ), PHP_EOL;
        return $counts['fail'] === 0 ? 0 : 1;
    }

    private function result(string $category, string $name, string $status, bool $required, mixed $detail, int|float $started): array
    {
        return [
            'category' => $category,
            'name' => $name,
            'status' => $status,
            'required' => $required,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            'detail' => $detail,
        ];
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function envRequired(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("{$name} is required");
    }
    return $value;
}

function createConnection(array $options = [], ?string $passwordOverride = null): PDO
{
    $driver = getenv('GAUSS_TEST_DRIVER') ?: 'pgsql';
    $user = getenv('GAUSS_USER') ?: 'gauss_php_test';
    $password = $passwordOverride ?? envRequired('GAUSS_PASSWORD');
    if ($driver === 'odbc') {
        $connectionString = getenv('GAUSS_ODBC_CONNECTION_STRING');
        $dsn = ($connectionString !== false && $connectionString !== '')
            ? "odbc:{$connectionString}"
            : 'odbc:' . (getenv('GAUSS_ODBC_DSN') ?: 'GaussDB');
    } elseif ($driver === 'pgsql') {
        $host = getenv('GAUSS_HOST') ?: 'gaussdb';
        $port = getenv('GAUSS_PORT') ?: '5432';
        $database = getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
    } else {
        throw new RuntimeException("Unsupported GAUSS_TEST_DRIVER: {$driver}");
    }
    return new PDO($dsn, $user, $password, $options + [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function executePrepared(PDO $pdo, string $sql, array $parameters = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach (array_values($parameters) as $index => $value) {
        $type = match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue($index + 1, $value, $type);
    }
    $statement->execute();
    return $statement;
}

$profile = getenv('GAUSS_TEST_PROFILE') ?: PHP_OS_FAMILY . '-' . php_uname('m');
$expectedDriver = getenv('GAUSS_TEST_DRIVER') ?: 'pgsql';
$runId = getenv('GAUSS_TEST_RUN_ID') ?: bin2hex(random_bytes(6));
$table = 'php_driver_contract_' . substr(hash('sha256', $profile . ':' . $runId), 0, 16);
$lastIdTable = $table . '_lastid';
$lobTable = $table . '_lob';
$runner = new ContractRunner();
$pdo = null;

$runner->test('environment', 'required PDO extension is loaded', function () use ($expectedDriver): array {
    $extension = $expectedDriver === 'odbc' ? 'pdo_odbc' : 'pdo_pgsql';
    expect(extension_loaded($extension), "Missing PHP extension {$extension}");
    return ['extension' => $extension, 'pdo_drivers' => PDO::getAvailableDrivers()];
});

$runner->test('connection', 'connect and identify driver', function () use (&$pdo, $expectedDriver): array {
    $pdo = createConnection();
    $actual = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    expect($actual === $expectedDriver, "Expected PDO driver {$expectedDriver}, got {$actual}");
    return [
        'pdo_driver' => $actual,
        'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
    ];
});

if (!$pdo instanceof PDO) {
    exit($runner->report(['profile' => $profile, 'php' => PHP_VERSION, 'os' => PHP_OS, 'architecture' => php_uname('m')]));
}

try {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
    $runner->test('ddl', 'create isolated test table', function () use ($pdo, $table): void {
        $pdo->exec("CREATE TABLE {$table} (
            id BIGINT PRIMARY KEY,
            name VARCHAR(256) NOT NULL,
            amount DECIMAL(20,4),
            enabled BOOLEAN,
            payload VARBINARY(256),
            note VARCHAR(256),
            created_at TIMESTAMP
        )");
    });

    $runner->test('crud', 'prepared insert and select', function () use ($pdo, $table): array {
        $statement = $pdo->prepare("INSERT INTO {$table} VALUES (?, ?, ?, ?, ?, ?, ?)");
        expect($statement->execute([1, 'basic', '1234567890123456.7890', 1, 'bytes', null, '2026-08-05 12:34:56']), 'Insert returned false');
        $select = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $select->execute([1]);
        $row = $select->fetch();
        expect(is_array($row) && (string) $row['id'] === '1', 'Inserted row was not returned');
        expect($row['amount'] === '1234567890123456.7890', 'DECIMAL precision changed');
        expect($row['note'] === null, 'NULL did not round-trip');
        return $row;
    });

    $runner->test('security', 'bound value resists SQL injection', function () use ($pdo, $table): void {
        $value = "x'); DROP TABLE {$table}; --";
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        $insert->execute([2, $value]);
        $select = $pdo->prepare("SELECT name FROM {$table} WHERE id = ?");
        $select->execute([2]);
        expect($select->fetchColumn() === $value, 'Bound string changed or was executed as SQL');
    });

    $runner->test('crud', 'update delete and affected row counts', function () use ($pdo, $table): void {
        $update = $pdo->prepare("UPDATE {$table} SET note = ? WHERE id = ?");
        $update->execute(['updated', 1]);
        expect($update->rowCount() === 1, 'UPDATE rowCount is not 1');
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $delete->execute([2]);
        expect($delete->rowCount() === 1, 'DELETE rowCount is not 1');
    });

    $runner->test('crud', 'empty string long text and BIGINT boundary', function () use ($pdo, $table): void {
        $id = '9223372036854775806';
        $name = str_repeat('A', 256);
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, note) VALUES (?, ?, ?)");
        $insert->execute([$id, $name, '']);
        $select = $pdo->prepare("SELECT id, name, note FROM {$table} WHERE id = ?");
        $select->execute([$id]);
        $row = $select->fetch();
        expect((string) $row['id'] === $id, 'BIGINT boundary changed');
        expect($row['name'] === $name, 'Maximum VARCHAR value changed');
        expect($row['note'] === '', 'Empty string changed or became NULL');
    });

    $runner->test('types', 'signed integer and negative decimal values', function () use ($pdo, $table): array {
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, amount) VALUES (?, ?, ?)");
        $insert->execute(['-9223372036854775807', 'negative', '-12345.6250']);
        $select = $pdo->prepare("SELECT id, amount FROM {$table} WHERE id = ?");
        $select->execute(['-9223372036854775807']);
        $row = $select->fetch();
        expect((string) $row['id'] === '-9223372036854775807', 'Negative BIGINT changed');
        expect((string) $row['amount'] === '-12345.6250', 'Negative DECIMAL changed');
        return $row;
    });

    $runner->test('types', 'timestamp boundary value', function () use ($pdo, $table): array {
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, created_at) VALUES (?, ?, ?)");
        $insert->execute([70, 'time-boundary', '2038-01-19 03:14:07']);
        $row = executePrepared($pdo, "SELECT created_at AS boundary_time FROM {$table} WHERE id = ?", [70])->fetch();
        expect(str_starts_with((string) $row['boundary_time'], '2038-01-19 03:14:07'), 'Timestamp boundary changed');
        return $row;
    });

    $runner->test('prepare', 'prepared statement can be reused', function () use ($pdo, $table): void {
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        foreach ([40 => 'batch-a', 41 => 'batch-b', 42 => 'batch-c'] as $id => $name) {
            $insert->execute([$id, $name]);
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id BETWEEN ? AND ?");
        $count->execute([40, 42]);
        expect((int) $count->fetchColumn() === 3, 'Reused statement did not insert all rows');
    });

    $runner->test('prepare', 'fetch modes and statement lifecycle', function () use ($pdo, $table): void {
        $statement = executePrepared($pdo, "SELECT id, name FROM {$table} WHERE id = ?", [1]);
        $assoc = $statement->fetch(PDO::FETCH_ASSOC);
        expect(isset($assoc['id'], $assoc['name']), 'FETCH_ASSOC did not return named columns');
        $statement->closeCursor();
        $statement = executePrepared($pdo, "SELECT id, name FROM {$table} WHERE id = ?", [1]);
        $numeric = $statement->fetch(PDO::FETCH_NUM);
        expect(count($numeric) === 2 && (string) $numeric[0] === '1', 'FETCH_NUM result is incorrect');
        $statement->closeCursor();
        expect((int) $pdo->query('SELECT 1')->fetchColumn() === 1, 'Connection unusable after closeCursor');
    });

    $runner->test('volume', 'batch insert and paged result set', function () use ($pdo, $table): array {
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        for ($id = 1000; $id < 1500; $id++) {
            $insert->execute([$id, 'batch-' . $id]);
        }
        $page = executePrepared($pdo, "SELECT id FROM {$table} WHERE id >= ? ORDER BY id LIMIT ? OFFSET ?", [1000, 50, 200])->fetchAll(PDO::FETCH_COLUMN);
        expect(count($page) === 50, 'Paged query did not return 50 rows');
        expect((string) $page[0] === '1200' && (string) $page[49] === '1249', 'ORDER BY/LIMIT/OFFSET result is incorrect');
        return ['inserted' => 500, 'page_size' => count($page)];
    });

    $runner->test('volume', 'large UTF-8 text round-trip', function () use ($pdo, $table): array {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN large_text TEXT");
        $value = str_repeat('GaussDB中文-', 32768);
        $update = $pdo->prepare("UPDATE {$table} SET large_text = ? WHERE id = 1");
        $update->execute([$value]);
        $actual = executePrepared($pdo, "SELECT large_text FROM {$table} WHERE id = ?", [1])->fetchColumn();
        expect($actual === $value, 'Large text did not round-trip exactly');
        return ['bytes' => strlen($value)];
    }, false);

    $runner->test('transaction', 'rollback removes uncommitted row', function () use ($pdo, $table): void {
        $pdo->beginTransaction();
        $insert = executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [10, 'rollback']);
        $insert->closeCursor();
        $pdo->rollBack();
        expect((int) executePrepared($pdo, "SELECT COUNT(*) FROM {$table} WHERE id = ?", [10])->fetchColumn() === 0, 'Rollback did not remove row');
    });

    $runner->test('transaction', 'commit persists row', function () use ($pdo, $table): void {
        $pdo->beginTransaction();
        $insert = executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [11, 'commit']);
        $insert->closeCursor();
        $pdo->commit();
        expect((int) executePrepared($pdo, "SELECT COUNT(*) FROM {$table} WHERE id = ?", [11])->fetchColumn() === 1, 'Commit did not persist row');
    });

    $runner->test('transaction', 'savepoint rollback preserves outer transaction', function () use ($pdo, $table): void {
        $pdo->beginTransaction();
        try {
            $insert = executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [12, 'outer']);
            $insert->closeCursor();
            $pdo->exec('SAVEPOINT php_contract_sp');
            $insert = executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [13, 'savepoint']);
            $insert->closeCursor();
            $pdo->exec('ROLLBACK TO SAVEPOINT php_contract_sp');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        expect((int) executePrepared($pdo, "SELECT COUNT(*) FROM {$table} WHERE id IN (?, ?)", [12, 13])->fetchColumn() === 1, 'Savepoint rollback result is incorrect');
    });

    $runner->test('error', 'duplicate key exposes SQLSTATE and connection recovers', function () use ($pdo, $table): array {
        try {
            executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [1, 'duplicate']);
            throw new RuntimeException('Duplicate primary key unexpectedly succeeded');
        } catch (PDOException $error) {
            expect(strlen((string) $error->getCode()) === 5, 'Driver did not expose a five-character SQLSTATE');
            $state = (string) $error->getCode();
        }
        expect((int) $pdo->query('SELECT 1')->fetchColumn() === 1, 'Connection cannot execute SQL after handled error');
        return ['sqlstate' => $state];
    });

    $runner->test('error', 'not-null violation is rejected', function () use ($pdo, $table): array {
        try {
            $insert = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
            $insert->execute([20, null]);
            throw new RuntimeException('NULL in NOT NULL column unexpectedly succeeded');
        } catch (PDOException $error) {
            return ['sqlstate' => (string) $error->getCode()];
        }
    });

    $runner->test('connection', 'second connection sees committed data', function () use ($table): void {
        $second = createConnection();
        expect((int) executePrepared($second, "SELECT COUNT(*) FROM {$table} WHERE id = ?", [11])->fetchColumn() === 1, 'Second connection cannot see committed row');
    });

    $runner->test('transaction', 'second connection cannot see uncommitted row', function () use ($pdo, $table): void {
        $second = createConnection();
        $pdo->beginTransaction();
        try {
            $insert = executePrepared($pdo, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [50, 'uncommitted']);
            $insert->closeCursor();
            expect((int) executePrepared($second, "SELECT COUNT(*) FROM {$table} WHERE id = ?", [50])->fetchColumn() === 0, 'Dirty read exposed uncommitted row');
            $pdo->rollBack();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    });

    $runner->test('metadata', 'standard and M private type metadata are available', function () use ($pdo, $table): array {
        $statement = executePrepared($pdo, "SELECT id, name, amount, enabled, payload FROM {$table} WHERE id = ?", [1]);
        expect($statement->columnCount() === 5, 'columnCount is not 5');
        $metadata = [];
        for ($index = 0; $index < 5; $index++) {
            $column = $statement->getColumnMeta($index);
            expect($column !== false, "getColumnMeta returned false for column {$index}");
            $metadata[(string) ($column['name'] ?? $index)] = $column;
        }
        expect(isset($metadata['enabled'], $metadata['payload']), 'BOOLEAN/VARBINARY metadata is missing');
        return $metadata;
    });

    $runner->test('identity', 'lastInsertId without generated identity is characterized', function () use ($pdo): array {
        try {
            return ['outcome' => 'value', 'value' => $pdo->lastInsertId()];
        } catch (PDOException $error) {
            return ['outcome' => 'exception', 'sqlstate' => (string) $error->getCode(), 'message' => $error->getMessage()];
        }
    }, false);

    $runner->test('identity', 'M AUTO_INCREMENT and PDO lastInsertId agree', function () use ($pdo, $lastIdTable): array {
        $pdo->exec("DROP TABLE IF EXISTS {$lastIdTable}");
        $pdo->exec("CREATE TABLE {$lastIdTable} (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64))");
        executePrepared($pdo, "INSERT INTO {$lastIdTable} (name) VALUES (?)", ['generated']);
        $lastId = $pdo->lastInsertId();
        $storedId = $pdo->query("SELECT id FROM {$lastIdTable}")->fetchColumn();
        $detail = ['last_insert_id' => $lastId, 'stored_id' => $storedId];
        expect($storedId !== false && $storedId !== null && (string) $storedId !== '', 'AUTO_INCREMENT did not store an identity: ' . json_encode($detail));
        expect((string) $storedId === (string) $lastId, 'lastInsertId differs from stored identity: ' . json_encode($detail));
        return $detail;
    }, false);

    $runner->test('lob', 'BYTEA type availability in M mode', function () use ($pdo, $lobTable): void {
        $pdo->exec("DROP TABLE IF EXISTS {$lobTable}");
        $pdo->exec("CREATE TABLE {$lobTable} (id BIGINT PRIMARY KEY, bytea_value BYTEA)");
    }, false);

    $runner->test('lob', 'BLOB basic round-trip', function () use ($pdo, $lobTable): array {
        $pdo->exec("DROP TABLE IF EXISTS {$lobTable}");
        $pdo->exec("CREATE TABLE {$lobTable} (id BIGINT PRIMARY KEY, blob_value BLOB)");
        $blob = str_repeat("B\x00", 4096);
        $statement = $pdo->prepare("INSERT INTO {$lobTable} VALUES (?, ?)");
        $statement->bindValue(1, 1, PDO::PARAM_INT);
        $statement->bindValue(2, $blob, PDO::PARAM_LOB);
        $statement->execute();
        $row = executePrepared($pdo, "SELECT blob_value FROM {$lobTable} WHERE id = ?", [1])->fetch();
        expect(is_array($row), 'LOB row was not returned');
        expect($row['blob_value'] === $blob, 'BLOB did not round-trip exactly');
        return ['blob_bytes' => strlen($blob)];
    }, false);

    $runner->test('lob', 'CLOB basic round-trip', function () use ($pdo, $lobTable): array {
        $pdo->exec("DROP TABLE IF EXISTS {$lobTable}");
        $pdo->exec("CREATE TABLE {$lobTable} (id BIGINT PRIMARY KEY, clob_value CLOB)");
        $clob = str_repeat('GaussDB LOB 中文-', 512);
        $statement = $pdo->prepare("INSERT INTO {$lobTable} VALUES (?, ?)");
        $statement->execute([1, $clob]);
        $row = executePrepared($pdo, "SELECT clob_value FROM {$lobTable} WHERE id = ?", [1])->fetch();
        expect(is_array($row), 'CLOB row was not returned');
        expect($row['clob_value'] === $clob, 'CLOB did not round-trip exactly');
        return ['clob_bytes' => strlen($clob)];
    }, false);

    $runner->test('lob', 'VARBINARY using PDO PARAM_LOB round-trip', function () use ($pdo, $table): array {
        $value = "LOB-A\x00B\xFFZ";
        $statement = $pdo->prepare("INSERT INTO {$table} (id, name, payload) VALUES (?, ?, ?)");
        $statement->bindValue(1, 34, PDO::PARAM_INT);
        $statement->bindValue(2, 'param-lob', PDO::PARAM_STR);
        $statement->bindValue(3, $value, PDO::PARAM_LOB);
        $statement->execute();
        $actual = executePrepared($pdo, "SELECT payload FROM {$table} WHERE id = ?", [34])->fetchColumn();
        expect($actual === $value, 'PDO::PARAM_LOB VARBINARY changed; got ' . bin2hex((string) $actual));
        return ['bytes' => strlen($value)];
    }, false);

    $runner->test('connection', 'persistent connection can execute and reset transaction', function () use ($table): void {
        $persistent = createConnection([PDO::ATTR_PERSISTENT => true]);
        expect((int) $persistent->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0, 'Persistent connection cannot query');
        $persistent->beginTransaction();
        $insert = executePrepared($persistent, "INSERT INTO {$table} (id, name) VALUES (?, ?)", [60, 'persistent-rollback']);
        $insert->closeCursor();
        $persistent->rollBack();
        expect((int) executePrepared($persistent, "SELECT COUNT(*) FROM {$table} WHERE id = ?", [60])->fetchColumn() === 0, 'Persistent connection rollback failed');
        $persistent = null;
    }, false);

    $runner->test('prepare', 'named parameter binding', function () use ($pdo, $table): void {
        $statement = $pdo->prepare("SELECT name FROM {$table} WHERE id = :id");
        $statement->execute(['id' => 1]);
        expect($statement->fetchColumn() === 'basic', 'Named parameter returned wrong row');
    }, false);

    $runner->test('transaction', 'DDL rollback behavior', function () use ($pdo, $table): void {
        $temporaryTable = $table . '_ddl';
        $pdo->exec("DROP TABLE IF EXISTS {$temporaryTable}");
        $pdo->beginTransaction();
        try {
            $pdo->exec("CREATE TABLE {$temporaryTable} (id BIGINT)");
            $pdo->rollBack();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        try {
            $pdo->query("SELECT * FROM {$temporaryTable}");
            $pdo->exec("DROP TABLE {$temporaryTable}");
            throw new RuntimeException('CREATE TABLE survived transaction rollback');
        } catch (PDOException) {
            // Expected: the table should not exist after rollback.
        }
    }, false);

    $runner->test('compatibility', 'UTF-8 Chinese and emoji round-trip', function () use ($pdo, $table): void {
        $value = '中文与 emoji 🚀';
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        $insert->execute([30, $value]);
        $actual = executePrepared($pdo, "SELECT name FROM {$table} WHERE id = ?", [30])->fetchColumn();
        expect($actual === $value, 'UTF-8 text changed: ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }, false);

    $runner->test('compatibility', 'boolean PDO parameter round-trip', function () use ($pdo, $table): void {
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, enabled) VALUES (?, ?, ?)");
        $insert->bindValue(1, 31, PDO::PARAM_INT);
        $insert->bindValue(2, 'bool', PDO::PARAM_STR);
        $insert->bindValue(3, true, PDO::PARAM_BOOL);
        $insert->execute();
        $actual = executePrepared($pdo, "SELECT enabled FROM {$table} WHERE id = ?", [31])->fetchColumn();
        expect(in_array($actual, [true, 1, '1'], true), 'Boolean true did not return as 1/true');
    }, false);

    $runner->test('compatibility', 'binary value containing NUL round-trip', function () use ($pdo, $table): void {
        $value = "A\x00B\xFFZ";
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, payload) VALUES (?, ?, ?)");
        $insert->execute([32, 'binary', $value]);
        $actual = executePrepared($pdo, "SELECT payload FROM {$table} WHERE id = ?", [32])->fetchColumn();
        expect($actual === $value, 'Binary value changed; expected hex ' . bin2hex($value) . ', got ' . bin2hex((string) $actual));
    }, false);

    $runner->test('compatibility', 'timestamp microseconds round-trip', function () use ($pdo, $table): void {
        $value = '2026-08-05 12:34:56.123456';
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, created_at) VALUES (?, ?, ?)");
        $insert->execute([33, 'timestamp', $value]);
        $actual = executePrepared($pdo, "SELECT created_at FROM {$table} WHERE id = ?", [33])->fetchColumn();
        expect($actual === $value, "Timestamp precision changed: {$actual}");
    }, false);
} finally {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DROP TABLE IF EXISTS {$lobTable}");
        $pdo->exec("DROP TABLE IF EXISTS {$lastIdTable}");
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup failed: ' . $cleanupError->getMessage() . PHP_EOL);
    }
}

exit($runner->report([
    'profile' => $profile,
    'php' => PHP_VERSION,
    'os' => PHP_OS,
    'architecture' => php_uname('m'),
    'expected_pdo_driver' => $expectedDriver,
]));
